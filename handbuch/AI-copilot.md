# AI copilot

The AI copilot explains check results and suggests fixes, using an AI model you configure: Claude, ChatGPT, Gemini, any model on OpenRouter, or a self-hosted model with an OpenAI-compatible API. If you prefer not to connect a model, the **export** puts the current state into the clipboard for any AI chat window.

The copilot is optional. Nothing is sent to an AI provider unless you click one of its buttons.

## Setting up models (Einstellungen → KI-Modelle)

| Field | Description |
| --- | --- |
| Name | Shown in the model selection, e.g. "Claude Opus". |
| Provider | Anthropic (Claude), OpenAI (ChatGPT), Google Gemini, OpenRouter, or OpenAI-compatible. |
| Model ID | Exactly as the provider names the model in its API, e.g. `claude-opus-5-5`. For OpenRouter the format is `provider/model`. |
| API key | Stored encrypted with `APP_KEY` and never shown again. Leave it empty when editing to keep it. Optional for OpenAI-compatible servers. |
| Base URL | Only needed for OpenAI-compatible servers (e.g. `http://ollama:11434/v1`) or a proxy; empty uses the provider's default. |
| Default model | Preselected in the copilot. Only one model can be the default. |
| Active | Inactive models are hidden in the copilot. |

**Test** on a model sends a short request and shows the answer or the provider's error message (invalid key, unknown model, rate limit).

| Provider | API used |
| --- | --- |
| Anthropic | Messages API through the official Anthropic PHP SDK, with server-side fallback models in case a model declines a request |
| OpenAI, OpenRouter, OpenAI-compatible | Chat Completions (`/chat/completions`) |
| Google Gemini | `generateContent` with a system instruction |

## Using the copilot

The copilot is available wherever results are shown: on the domain page (*Aktueller Stand*), on a check run and in the mail security tools.

- **KI-Empfehlung** inside the details of every result that is not OK asks for recommendations on that one check. The other results are sent as a short overview so the model knows the context.
- **KI-Copilot** in the header of the results asks about all errors, warnings and info messages at once.
- The answer opens in a dialog as formatted text, sorted by urgency, with concrete steps and ready-to-use DNS records where possible. Missing values are shown as placeholders in angle brackets.
- Switch the model in the dialog to compare answers; **Neu generieren** asks again, **Antwort kopieren** copies the answer as Markdown.
- Answers are cached for 7 days per model and identical results, so opening the same recommendation again costs nothing. A new check run with changed results asks again.

## Export for an AI chat

**Export für KI-Chat** shows the instructions and the current state of all checks as one text: status, summary, every message and the record data. Copy it to the clipboard or save it as a Markdown file, then paste it into ChatGPT, Claude, Gemini or any other chat. This works without any configured model.

## Privacy and security

- When you ask the copilot, the results of that domain (domain name, records, messages) are sent to the provider of the selected model. Check the provider's data processing terms before use; a self-hosted model keeps everything on your own infrastructure.
- API keys are encrypted with `APP_KEY` and are never sent to the browser.
- Record contents come from DNS and foreign servers. The instructions tell the model to treat them as data only, never as instructions, and the answer is rendered without HTML, so a crafted TXT record cannot inject scripts into the backend.
