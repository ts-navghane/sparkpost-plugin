### Mautic Sparkpost Plugin

This plugin enable Mautic 5 to run Sparkpost as a transport.

#### Usage
`composer require ts-navghane/sparkpost-plugin`

#### Mautic Mailer DSN Scheme
`mautic+sparkpost+api`

#### Mautic Mailer DSN Example
`mautic+sparkpost+api://:<api_key>@<host>:<port>?region=us`
- api_key: Get Sparkpost API key from https://app.sparkpost.com/account/api-keys/create
- host: Your Sparkpost host
- port: Your Sparkpost port
- options:
  - region: Your Sparkpost region

<img width="1105" alt="Screenshot 2023-08-07 at 12 57 39" src="https://github.com/escopecz/sparkpost-plugin/assets/1235442/acb8b5fc-6315-4ca7-a9ba-ec9822eb8eb4">
