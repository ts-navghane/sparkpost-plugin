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

### Testing

To run all tests `composer phpunit`

To run unit tests `composer unit`

To run functional tests `composer functional`

### Static analysis tools

To run fixes by friendsofphp/php-cs-fixer `composer fixcs`

To run phpstan `composer phpstan`
