---
Purpose: Confirm an account/workspace deletion request and state what happens next.
Channel: email (transactional / security)
Placeholders: {{first_name}}, {{workspace_name}}, {{requested_at}}, {{deletion_date}}, {{cancel_url}}, {{export_url}}, {{support_email}}
---

# Account deletion confirmation

**Subject line:** Your Spi account is scheduled for deletion

**Preview text:** Deletion completes on {{deletion_date}}. You can still cancel.

---

Hi {{first_name}},

We've received a request to delete your Spi account and the **{{workspace_name}}** workspace, made on {{requested_at}}.

**What happens next**

- Your account and workspace are scheduled for permanent deletion on **{{deletion_date}}**.
- After that date, we remove your collections, monitors, run history, reports, and API keys. This can't be undone.
- Active API keys stop working immediately on deletion. Any monitors and scheduled checks will stop running.

**Before it's gone**

- [Export your data]({{export_url}}) — collections and reports export as JSON or Markdown.
- [Cancel the deletion]({{cancel_url}}) if this was a mistake or you've changed your mind.

If you didn't request this, cancel now and secure your account, then contact us at {{support_email}}.

— The Spi team
