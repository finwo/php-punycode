Finwo / Punycode
================
Non-length-limited punycode en-/decoder

## Usage

Encoding a string

```php
$encodedString = Punycode::encode( $stringToEncode );
```

Decoding a string

```php
$decodedString = Punycode::decode( $encodedString );
```

Checking if a string is valid punycode

```php
$isValid = Punycode::isPunycode( $stringToTest );
```

## Contributing

After checking the [Github issues](https://github.com/finwo/php-punycode/issues) and confirming that your request isn't already being worked on, feel free to spawn a new fork and send in a pull request.
