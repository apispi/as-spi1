<template>
  <div class="legal-page">
    <div class="legal-container">
      <header class="legal-header">
        <router-link to="/" class="legal-back">← Back</router-link>
        <h1>Privacy Notice</h1>
        <p class="legal-updated">Last updated: 13 August 2026</p>
        <p class="legal-lead">
          This notice explains what information Spi (apispi.com) collects, why
          we collect it, and the choices you have. We collect the minimum we
          need to run an API testing service — we do not sell your data.
        </p>
      </header>

      <section class="legal-section">
        <h2>1. Information we collect</h2>
        <ul>
          <li>
            <strong>Account data</strong> — your name, email address, and a
            hashed password. If you sign in with Google, we receive your name,
            email, and Google account identifier from Google; we never see
            your Google password.
          </li>
          <li>
            <strong>Request data</strong> — the requests you send through the
            testers (URLs, headers, bodies, connection settings) and the
            responses received, stored as your request history and saved
            requests so you can replay and inspect them.
          </li>
          <li>
            <strong>Reports and catalog data</strong> — inspection reports you
            save and connector configurations you add. Connector auth headers
            are stored only on the connector record and are never exposed
            through public endpoints.
          </li>
          <li>
            <strong>API keys</strong> — personal API keys are stored hashed;
            we cannot recover a key after it is shown to you once.
          </li>
          <li>
            <strong>Usage data</strong> — standard server logs (IP address,
            timestamps, endpoints called) and, on public pages, analytics
            events via Google Analytics (see cookies below).
          </li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>2. How we use it</h2>
        <p>
          We use your information to operate the service (authenticate you,
          proxy your requests, keep your history and reports), to secure it
          (rate limiting, abuse prevention, blocking requests to private
          networks), to communicate with you about your account (such as email
          verification), and to understand aggregate usage so we can improve
          the product. We do not sell or rent personal information, and we do
          not use your request payloads for advertising.
        </p>
      </section>

      <section class="legal-section">
        <h2>3. Cookies and analytics</h2>
        <p>
          Spi uses a session cookie and a CSRF token cookie that are essential
          for signing in and protecting your account. We also use Google
          Analytics (gtag.js) to measure page usage; Google Analytics sets its
          own cookies and processes data under
          <a class="legal-link" href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google's privacy policy</a>.
          You can block analytics cookies with your browser settings or an
          opt-out extension without affecting the service.
        </p>
      </section>

      <section class="legal-section">
        <h2>4. Sharing</h2>
        <p>
          Requests you send through the testers are transmitted to the target
          systems you specify — the data in those requests is handled by those
          third parties under their own policies. If you create a shareable
          report link, the report is visible to anyone who has the link until
          you revoke it. Beyond that, we share data only with infrastructure
          providers that host and operate the service, or when required by
          law.
        </p>
      </section>

      <section class="legal-section">
        <h2>5. Retention and deletion</h2>
        <p>
          Account data and content you create (history, saved requests,
          reports, connectors) are kept while your account is active. You can
          delete individual saved requests, history entries, and reports from
          the dashboard at any time. To delete your account and its data
          entirely, contact us at the address below and we will action the
          request promptly.
        </p>
      </section>

      <section class="legal-section">
        <h2>6. Security</h2>
        <p>
          Passwords and API keys are stored hashed, traffic is served over
          HTTPS, and outbound test requests pass through safeguards that block
          access to private and reserved networks. No system is perfectly
          secure — avoid putting production secrets in test requests where
          possible, and rotate any credential you have used in testing.
        </p>
      </section>

      <section class="legal-section">
        <h2>7. Your rights</h2>
        <p>
          Depending on where you live, you may have rights to access, correct,
          export, or delete your personal information, or to object to certain
          processing. To exercise any of these rights, contact us and we will
          respond within a reasonable timeframe.
        </p>
      </section>

      <section class="legal-section">
        <h2>8. Changes to this notice</h2>
        <p>
          We may update this notice from time to time; the "Last updated" date
          above reflects the current version. Material changes will be
          highlighted on this page.
        </p>
      </section>

      <section class="legal-section">
        <h2>9. Contact</h2>
        <p>
          Privacy questions or requests? Email
          <a class="legal-link" href="mailto:support@apispi.com">support@apispi.com</a>.
          See also our <router-link class="legal-link" to="/terms">Terms &amp; Conditions</router-link>.
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
