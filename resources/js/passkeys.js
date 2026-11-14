// Passkey-Client für das Filament-Panel (Login + Verwaltung).
// Liest das XSRF-TOKEN-Cookie automatisch für die POST-Requests.
import { Passkeys, UserCancelledError, NotSupportedError, PasskeyExistsError } from '@laravel/passkeys';

function message(error) {
    if (error instanceof UserCancelledError) return null;
    if (error instanceof NotSupportedError) return 'Dieser Browser unterstützt keine Passkeys.';
    if (error instanceof PasskeyExistsError) return 'Dieser Passkey ist bereits registriert.';
    return error?.message || 'Unbekannter Fehler.';
}

window.DomainMonitorPasskeys = {
    supported: () => Passkeys.isSupported(),

    async login() {
        try {
            const response = await Passkeys.verify({ remember: true });
            window.location.href = response?.redirect || '/admin';
            return null;
        } catch (error) {
            return message(error);
        }
    },

    async register(name) {
        try {
            await Passkeys.register({ name: name || 'Passkey' });
            return null;
        } catch (error) {
            return message(error) ?? '';
        }
    },
};
