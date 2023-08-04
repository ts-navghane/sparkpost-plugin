### Mautic Sparkpost Plugin

This plugin enable Mautic 5 to run Sparkpost as a transport.

#### Usage
`composer require ts-navghane/sparkpost-plugin`

#### Mautic Mailer DSN Scheme
`mautic+sparkpost+api`

#### Mautic Mailer DSN Example
`'mailer_dsn' => 'mautic+sparkpost+api://:<api_key>@default?region=<region>',`
- api_key: Get Sparkpost API key from https://app.sparkpost.com/account/api-keys/create
- options:
  - region: `us` (SparkPost https://api.sparkpost.com/api/v1) OR `eu` (SparkPost EU https://api.eu.sparkpost.com/api/v1)

### Testing

To run all tests `composer phpunit`

To run unit tests `composer unit`

To run functional tests `composer functional`

### Static analysis tools

To run fixes by friendsofphp/php-cs-fixer `composer fixcs`

To run phpstan `composer phpstan`
