<template>
  <div class="legal-page">
    <div class="legal-container">
      <header class="legal-header">
        <router-link to="/" class="legal-back">← Back</router-link>
        <h1>Terms &amp; Conditions</h1>
        <p class="legal-updated">Last updated: 13 August 2026</p>
        <p class="legal-lead">
          These terms govern your use of Spi (apispi.com), a multi-protocol API
          testing service. By creating an account or using the service you agree
          to these terms. If you do not agree, do not use the service.
        </p>
      </header>

      <section class="legal-section">
        <h2>1. The service</h2>
        <p>
          Spi lets you send and inspect requests across REST, GraphQL,
          WebSocket, SOAP, Webhook, MCP, A2A, gRPC, MQTT, and AMQP; save and
          replay requests; generate inspection reports; and access the same
          capabilities programmatically through a personal API key. The service
          is provided on an "as is" and "as available" basis and may change,
          be suspended, or be discontinued at any time without notice.
        </p>
      </section>

      <section class="legal-section">
        <h2>2. Your account</h2>
        <p>
          You must provide accurate registration information and keep your
          credentials and API keys confidential. You are responsible for all
          activity that occurs under your account or your API keys. Notify us
          promptly if you believe your account has been compromised.
          Regenerating an API key immediately invalidates the previous one.
        </p>
      </section>

      <section class="legal-section">
        <h2>3. Acceptable use</h2>
        <p>When using Spi you agree that you will <strong>not</strong>:</p>
        <ul>
          <li>
            send requests to systems you do not own or lack authorization to
            test — you are solely responsible for having permission to test
            every endpoint, server, broker, or agent you target;
          </li>
          <li>
            use the service for denial-of-service, brute-forcing, scanning,
            spamming, or any other attack or abusive traffic pattern;
          </li>
          <li>
            attempt to reach private, internal, or reserved network addresses,
            or otherwise circumvent the service's safeguards or rate limits;
          </li>
          <li>use the service to violate any applicable law or regulation;</li>
          <li>
            resell, sublicense, or provide the service to third parties as
            your own offering without our written consent.
          </li>
        </ul>
        <p>
          We may throttle, suspend, or terminate accounts that breach these
          rules, and may remove content or shared reports at our discretion.
        </p>
      </section>

      <section class="legal-section">
        <h2>4. Your content</h2>
        <p>
          You retain ownership of the requests, payloads, connector
          configurations, and reports you create. You grant us the limited
          right to store and process that content solely to operate the
          service (for example, to proxy your requests, keep your history, and
          render reports you choose to share). If you create a shareable
          report link, anyone with that link can view the report — share links
          carefully and revoke them when no longer needed.
        </p>
      </section>

      <section class="legal-section">
        <h2>5. Third-party systems</h2>
        <p>
          Requests you send through Spi reach third-party servers of your
          choosing. We are not responsible for the content, availability,
          conduct, or data-handling practices of any third-party system you
          test, and any data you include in a request is transmitted to that
          third party under their terms, not ours.
        </p>
      </section>

      <section class="legal-section">
        <h2>6. Disclaimer and limitation of liability</h2>
        <p>
          To the maximum extent permitted by law, the service is provided
          without warranties of any kind, express or implied, including
          fitness for a particular purpose and non-infringement. We do not
          warrant that the service will be uninterrupted, error-free, or
          secure. To the maximum extent permitted by law, we will not be
          liable for any indirect, incidental, special, consequential, or
          punitive damages, or for loss of data, profits, or business, arising
          from your use of the service.
        </p>
      </section>

      <section class="legal-section">
        <h2>7. Termination</h2>
        <p>
          You may stop using the service and request deletion of your account
          at any time. We may suspend or terminate your access if you breach
          these terms. Sections that by their nature should survive
          termination (including 4–6) survive it.
        </p>
      </section>

      <section class="legal-section">
        <h2>8. Changes to these terms</h2>
        <p>
          We may update these terms from time to time. The "Last updated" date
          above reflects the current version. Continued use of the service
          after changes take effect constitutes acceptance of the revised
          terms.
        </p>
      </section>

      <section class="legal-section">
        <h2>9. Contact</h2>
        <p>
          Questions about these terms? Contact us at
          <a class="legal-link" href="mailto:support@apispi.com">support@apispi.com</a>.
          See also our <router-link class="legal-link" to="/privacy">Privacy Notice</router-link>.
        </p>
      </section>
    </div>
  </div>
</template>

<style scoped>
.legal-page { min-height: 100%; background: var(--bg-color); color: var(--text-primary); overflow-y: auto; }
.legal-container { max-width: 820px; margin: 0 auto; padding: 40px 24px 80px; }

.legal-header { margin-bottom: 32px; }
.legal-back { color: var(--accent-color); text-decoration: none; font-size: 14px; }
.legal-header h1 { font-size: 32px; font-weight: 700; margin: 12px 0 4px; }
.legal-updated { color: var(--text-secondary); font-size: 13px; margin: 0 0 12px; }
.legal-lead { color: var(--text-secondary); font-size: 16px; line-height: 1.6; margin: 0; }

.legal-section { margin-bottom: 32px; }
.legal-section h2 { font-size: 20px; font-weight: 600; margin: 0 0 10px; }
.legal-section p { line-height: 1.7; color: var(--text-primary); margin: 0 0 12px; }
.legal-section ul { margin: 0 0 12px; padding-left: 22px; }
.legal-section li { line-height: 1.7; margin-bottom: 6px; }
.legal-link { color: var(--accent-color); text-decoration: none; }
.legal-link:hover { text-decoration: underline; }
</style>
