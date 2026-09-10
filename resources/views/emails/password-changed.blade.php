@component('mail::message')
# Your password was changed

Hi {{ $firstName }},

Your Spi account password was just changed on **{{ $when }}**@if($ip) from IP **{{ $ip }}**@endif.

If this was you, no action is needed.

**If it wasn't you**, your account may be compromised. Act now:

1. Reset your password from **Profile → Account**, and turn on two-factor authentication.
2. Review your active sessions and sign out any you don't recognise.
3. Revoke any API keys you didn't create.

@component('mail::button', ['url' => $securityUrl])
Review account security
@endcomponent

Need help? Contact us at {{ $supportEmail }}.

Thanks,<br>
The Spi Team
@endcomponent
