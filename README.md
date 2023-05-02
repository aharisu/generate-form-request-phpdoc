# Generate FormRequest PHPDoc
Generate PHPDoc properties automatically based on the rules specification of the FormRequest class.

## Installation
```bash
composer require --dev aharisu/generate-form-request-phpdoc
```

## Usage

```bash
php artisan form-request:generate
```
Outputs the PHPDoc of the classes inheriting from `FormRequest` in the `app/Http/Requests` directory to an external file (e.g. `_form_request_phpdoc.php`).

### For example
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FooRequest extends FormRequest
{
    public function rules()
    {
        return [
            'id' => ["required", "integer"],
            'int_value' => ["nullable", "integer"],
            'numeric_value' => ["required", "numeric"],
            'string_value' => ["required", "string"],
            'bool_value' => ["nullable", "boolean"],
            'json_value' => ["required", "json"],
            'indexed_array' => ["required", "array"],
            'indexed_array.*' => ["required", "string"],
            'shaped_array' => ["nullable", "array"],
            'shaped_array.id' => ["required", "int"],
            'shaped_array.name' => ["required", "string"],
            'shaped_array.thumbnail' => ["nullable", "file"],
        ];
    }
}
```
```php
namespace App\Http\Requests {
/**
 * @property-read int $id
 * @property-read ?int $int_value
 * @property-read int|float $numeric_value
 * @property-read string $string_value
 * @property-read ?bool $bool_value
 * @property-read mixed $json_value
 * @property-read string[] $indexed_array
 * @property-read ?array{id: int, name: string, thumbnail: ?\Illuminate\Http\UploadedFile} $shaped_array
 */
class FooRequest extends \Illuminate\Foundation\Http\FormRequest {}
}
```

## Arguments
- `--write` Write PHPDoc directly to your FormRequest file

## Target specification
You can specify the output target by the `class name with namespace` or the `file path`.
```bash
php artisan form-request:generate App\Http\Requests\FooRequest
```
```bash
php artisan form-request:generate app/Http/Requests/FooRequest.php
```

Multiple specifications are also possible.
```bash
php artisan form-request:generate App\Http\Requests\FooRequest app/Http/Requests/BarRequest.php
```

## Other settings
```bash
php artisan vendor:publish --provider="aharisu\GenerateFormRequestPHPDoc\GenerateFormRequestPhpdocServiceProvider"
```
You can change some behaviors in `config/generate-form-request-phpdoc.php`.

## License

Apache 2.0 & MIT