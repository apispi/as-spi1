---
Purpose: Security notice confirming a password change; give the user a path to react if it wasn't them.
Channel: email (transactional / security)
Placeholders: {{first_name}}, {{changed_at}}, {{ip_address}}, {{location}}, {{secure_account_url}}, {{support_email}}
---

# Password changed

**Subject line:** Your Spi password was changed

**Preview text:** If this wasn't you, secure your account now.

---

Hi {{first_name}},

The password on your Spi account was changed on **{{changed_at}}** from {{location}} ({{ip_address}}).

**If this was you,** no action is needed.

**If this wasn't you,** secure your account right away:

[Secure my account]({{secure_account_url}})

Then review your active sessions and revoke any you don't recognise, rotate your API keys, and check the security audit log. We recommend turning on two-factor authentication (TOTP) if you haven't already.

Reach us at {{support_email}} if you need help.

— The Spi team
