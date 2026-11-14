<?php

namespace App\Support\Tls;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Holt das Serverzertifikat per direktem TLS (HTTPS) oder per SMTP-STARTTLS.
 * Erst mit Prüfung gegen die System-CAs; schlägt das fehl, ein zweiter Versuch
 * ohne Prüfung, damit das Zertifikat trotzdem angezeigt werden kann.
 */
class TlsInspector
{
    public function __construct(private readonly float $timeout = 10.0) {}

    public function https(string $host, int $port = 443): CertificateInfo
    {
        try {
            return $this->direct($host, $port, true);
        } catch (RuntimeException $e) {
            $info = $this->direct($host, $port, false);

            return $this->withError($info, $e->getMessage());
        }
    }

    public function starttls(string $host, int $port = 25): CertificateInfo
    {
        try {
            return $this->smtp($host, $port, true);
        } catch (StartTlsUnsupported $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $info = $this->smtp($host, $port, false);

            return $this->withError($info, $e->getMessage());
        }
    }

    private function context(string $host, bool $verify)
    {
        return stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => ! $verify,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
    }

    private function direct(string $host, int $port, bool $verify): CertificateInfo
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $this->context($host, $verify));
        if (! $stream) {
            $last = error_get_last()['message'] ?? '';
            $reason = $errstr ?: preg_replace('/^stream_socket_client\(\): /', '', $last);
            throw new RuntimeException($this->cleanError($reason ?: 'Verbindung fehlgeschlagen'));
        }

        return $this->extract($stream, $verify);
    }

    private function smtp(string $host, int $port, bool $verify): CertificateInfo
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $this->context($host, $verify));
        if (! $stream) {
            throw new RuntimeException('Port '.$port.' nicht erreichbar: '.($errstr ?: 'Timeout'));
        }
        stream_set_timeout($stream, (int) $this->timeout);

        $this->expect($stream, '220');
        fwrite($stream, 'EHLO '.(gethostname() ?: 'domain-monitor.local')."\r\n");
        $ehlo = $this->expect($stream, '250');
        if (stripos($ehlo, 'STARTTLS') === false) {
            fclose($stream);
            throw new StartTlsUnsupported('Server bietet kein STARTTLS an.');
        }
        fwrite($stream, "STARTTLS\r\n");
        $this->expect($stream, '220');

        set_error_handler(fn () => true);
        try {
            $ok = stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            restore_error_handler();
        }
        if ($ok !== true) {
            $err = openssl_error_string() ?: 'TLS-Handshake fehlgeschlagen';
            throw new RuntimeException($this->cleanError($err));
        }

        @fwrite($stream, "QUIT\r\n");

        return $this->extract($stream, $verify);
    }

    /** Liest eine (ggf. mehrzeilige) SMTP-Antwort. */
    private function expect($stream, string $code): string
    {
        $response = '';
        while (($line = fgets($stream, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if (! str_starts_with($response, $code)) {
            throw new RuntimeException('Unerwartete SMTP-Antwort: '.($response === '' ? 'keine Antwort' : trim(mb_strimwidth($response, 0, 120))));
        }

        return $response;
    }

    private function extract($stream, bool $verified): CertificateInfo
    {
        $params = stream_context_get_params($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $cert) {
            throw new RuntimeException('Kein Zertifikat erhalten.');
        }
        $parsed = openssl_x509_parse($cert);
        $names = [];
        foreach (explode(',', $parsed['extensions']['subjectAltName'] ?? '') as $san) {
            $san = trim($san);
            if (str_starts_with($san, 'DNS:')) {
                $names[] = substr($san, 4);
            }
        }
        if ($names === [] && isset($parsed['subject']['CN'])) {
            $names[] = (string) $parsed['subject']['CN'];
        }

        $issuer = $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? 'unbekannt';
        if (isset($parsed['issuer']['CN']) && isset($parsed['issuer']['O'])) {
            $issuer = $parsed['issuer']['O'].' ('.$parsed['issuer']['CN'].')';
        }

        return new CertificateInfo(
            subject: (string) ($parsed['subject']['CN'] ?? $names[0] ?? ''),
            issuer: (string) $issuer,
            names: $names,
            validFrom: CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']),
            validTo: CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']),
            fingerprint: (string) openssl_x509_fingerprint($cert, 'sha256'),
            trusted: $verified,
            protocol: $meta['crypto']['protocol'] ?? null,
        );
    }

    private function withError(CertificateInfo $info, string $error): CertificateInfo
    {
        return new CertificateInfo($info->subject, $info->issuer, $info->names, $info->validFrom, $info->validTo, $info->fingerprint, false, $error, $info->protocol);
    }

    private function cleanError(string $error): string
    {
        return trim(preg_replace('/\s+/', ' ', $error));
    }
}
