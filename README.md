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
