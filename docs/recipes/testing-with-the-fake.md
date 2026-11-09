# Test code that uses settings

Swap the engine for one over in-memory layers and assert on what was written.

```php
use Simtabi\Laranail\Settings\Facades\Settings;

it('saves the chosen theme', function () {
    $settings = Settings::fake();

    $this->post('/preferences', ['theme' => 'dark']);

    $settings->assertSaved('ui.theme', 'dark');
    $settings->assertNotSaved('ui.density');
});
```

The fake runs the real engine, so definitions, validation and casting still apply, and nothing is written to disk. Install it before the code under test resolves `Settings`: anything resolved earlier keeps the real instance.

| Assertion | Passes when |
|---|---|
| `assertSaved($key)` | the key was saved |
| `assertSaved($key, $value)` | the key was saved with exactly this value |
| `assertNotSaved($key)` | the key was never saved |
| `assertForgotten($key)` | the key was forgotten |

---

[← Docs index](../../README.md#documentation)
