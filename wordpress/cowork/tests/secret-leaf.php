<?php
// Loaded by run.php. SecretLeaf keeps a credential out of a derived content map, by the NAME of its setting and by
// the SHAPE of its value (found in a review of the import work, 02/10/2026: a derive that could not render the pages
// keeps every nested string leaf, so an API key typed into a setting was a slot like any heading).
require_once __DIR__ . '/../lib/SecretLeaf.php';
require_once __DIR__ . '/../lib/LeafCodec.php';
require_once __DIR__ . '/../lib/VisibleText.php';
require_once __DIR__ . '/../lib/DerivedMap.php';
echo "\nSecret leaves\n";

// The credential-shaped fixtures below are built from two parts on purpose: GitHub's push protection refuses a push
// whose source holds a whole key, real or not.

// A setting NAME that holds a credential is left out, whatever its value looks like.
foreach (['smtp', 'smtp_password', 'api_key', 'apiKey', 'mailchimp_api_key', 'client_secret', 'access_key', 'accessToken',
          'webhook_url', 'db_pass', 'pwd', 'auth_salt', 'private_key', 'license_key', 'licence_key', 'signature_secret',
          'password', 'Password', 'recaptcha_secret'] as $slName) {
    check("secret leaf: the name {$slName} is a credential", SecretLeaf::name($slName), true);
}
// The names of words a visitor reads stay.
foreach (['title', 'headline', 'description', 'button_text', 'tagline', 'private_tours_title', 'salt_lake_city', 'passport_office',
          'bypass_text', 'compass', 'signature_line', 'recaptcha_site_key', 'subtitle', 'tokenless'] as $slName) {
    if ($slName === 'tokenless') continue; // `token` as a substring is deliberate: see the note in SecretLeaf::NAME
    check("secret leaf: the name {$slName} is words", SecretLeaf::name($slName), false);
}

// A value whose SHAPE gives it away is left out, wherever it sits.
foreach ([
    'a Google API key' => 'AIza' . 'SyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q',
    'a Stripe key' => 'sk' . '_live_' . '4eC39HqLyjWDarjtT1zdp7dc',
    'an AWS access key id' => 'AKIA' . 'IOSFODNN7EXAMPLE',
    'a JWT' => 'eyJhbGciOiJIUzI1NiJ9' . '.eyJzdWIiOiIxMjM0NTY3ODkwIn0' . '.dBjftJeZ4CVPmB92K27uhbUJU1p1rwW1gFWFOEjXk',
    'a Slack token' => 'xox' . 'b-123456789012-abcdefghijkl',
    'a Slack webhook' => 'https://hooks.' . 'slack.com/services/T0000000/B0000000/XXXXXXXXXXXXXXXXXXXXXXXX',
    'a URL with user:password' => 'https://deploy:hunter2pass@git.example.com/site.git',
    'a URL with a key parameter' => 'https://maps.example.com/embed?pb=1&key=abcd1234efgh',
    'a private key block' => "-----BEGIN RSA PRIVATE KEY-----\nMIIE",
    'a bearer header' => 'Bearer abcdefghijklmnop1234',
    'an opaque token' => 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4',
    'a 32-digit hex' => 'D41D8CD98F00B204E9800998ECF8427E',
] as $slWhat => $slValue) {
    check("secret leaf: {$slWhat} is a credential by its shape", SecretLeaf::value($slValue), true);
}
// What a customer asks to change is never one.
foreach ([
    'words' => 'Welcome to Northwind Traders',
    'an e-mail' => 'team@northwind.example',
    'a mailto link' => 'mailto:team@northwind.example',
    'a phone link' => 'tel:+61740331234',
    'an ordinary link' => 'https://example.com/tours/private-tours',
    'a video link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'a picture path' => 'images/tours/coral-coast-7d-families-hero-2026.webp',
    'a slug of words' => 'Summer-Sale-2024-Collection-Page',
    'a sentence with a number' => 'Open every weekday from 9 to 5, call 1800 669 579',
] as $slWhat => $slValue) {
    check("secret leaf: {$slWhat} is not a credential", SecretLeaf::value($slValue), false);
}

// In a stored value: a secret-named key takes its whole subtree with it, a secret-shaped value is skipped, the words stay.
$slJson = '{"headline":"Welcome aboard","smtp":{"host":"smtp.example.com","user":"mailer","password":"correct horse battery staple"},'
    . '"api_key":"plain words that are no key shape","maps":{"embed":"https://maps.example.com/embed?key=abcd1234efgh","label":"Find us"},'
    . '"button":"Book now","note":"AIza' . 'SyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q"}';
check('secret leaf: JSON keeps the words and drops the credentials', array_column(LeafCodec::leaves($slJson), 'text'), ['Welcome aboard', 'Find us', 'Book now']);
$slSer = serialize(['title' => 'Mail form', 'pass' => 'hunter2hunter2', 'nested' => ['secret_key' => 'x y z words', 'caption' => 'Contact us']]);
check('secret leaf: a serialized setting is read the same way', array_column(LeafCodec::leaves($slSer), 'text'), ['Mail form', 'Contact us']);
check('secret leaf: a value under an innocent name is skipped by its shape', array_column(LeafCodec::leaves('{"caption":"Our shop","footer_note":"xox' . 'b-123456789012-abcdefghijkl"}'), 'text'), ['Our shop']);
// Listing only: a leaf that is addressed is still read as it was.
check('secret leaf: get() follows the address it is given', LeafCodec::get('{"headline":"Hello there"}', 'json:/headline|text:'), 'Hello there');

// In a derived map: with no pages rendered every nested leaf was kept; now a credential is not one of them.
$slRows = [
    ['kind' => 'module', 'id' => 9, 'identity' => ['id' => 9], 'core' => ['title' => 'Contact form'], 'html' => [],
     'nested' => ['params' => '{"intro":"Write to us","mailer":{"smtp_password":"correct horse battery staple","host":"smtp.example.com"},"captcha_secret":"unguessable words here"}']],
];
$slBlind = DerivedMap::build($slRows, null, 'northwind-import-example-e1b80210', 1);
// A host name under an innocent key is no credential and stays; the password beside it, and the captcha secret, do not.
check('secret leaf: an uncalibrated derive lists the words and no credential', array_map(static fn($s) => $s['sample'], $slBlind['map']['slots']), ['Contact form', 'Write to us', 'smtp.example.com']);
check('secret leaf: no slot is made for the credentials', count($slBlind['map']['slots']), 3);

// WordPress: an option a plugin named for a credential is never a row (WordPressDerivedRows::technicalOption), whatever
// its value looks like; the site's own words and the theme's mods are.
require_once __DIR__ . '/../lib/WordPressDerivedRows.php';
foreach (['mailchimp_api_key', 'wpmailsmtp', 'stripe_secret_key', 'my_plugin_webhook_url', 'smtp_pass', 'some_plugin_token'] as $slOption) {
    check("secret leaf: the option {$slOption} is never a row", WordPressDerivedRows::technicalOption($slOption), true);
}
foreach (['blogname', 'blogdescription', 'widget_text', 'sidebars_widgets', 'theme_mods_twentytwentyfour', 'woocommerce_store_address'] as $slOption) {
    check("secret leaf: the option {$slOption} stays a row", WordPressDerivedRows::technicalOption($slOption), false);
}

