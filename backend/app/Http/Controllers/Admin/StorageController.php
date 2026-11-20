<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Storage\StorageAddress;
use App\Services\Storage\StorageConfig;
use App\Services\Storage\StorageDriverFactory;
use App\Services\Storage\StorageException;
use App\Services\Storage\StorageNotConfiguredException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class StorageController extends Controller
{
    public function index()
    {
        $config = StorageConfig::fromSettings();
        $address = $config->address();
        $legacyPath = Setting::get('storage_path');

        return view('admin.storage.index', [
            'address' => $config->rawAddress(),
            'protocol' => $address?->protocol(),
            'display_root' => $address?->display(),
            'username' => $config->username(),
            'password_set' => (bool) Setting::get('storage_password'),
            'domain' => $config->domain(),
            'legacy_path' => $legacyPath,
            'legacy_active' => $address === null && $legacyPath !== null && $legacyPath !== '' && is_dir($legacyPath),
            'connect_timeout' => $config->connectTimeout(),
            'read_timeout' => $config->readTimeout(),
            'verify_tls' => $config->verifyTls(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $this->validateSettings($request);

        Setting::put('storage_address', $data['address']);
        Setting::put('storage_username', ($data['username'] ?? '') !== '' ? $data['username'] : null);
        Setting::put('storage_domain', ($data['domain'] ?? '') !== '' ? $data['domain'] : null);
        Setting::put('storage_connect_timeout', isset($data['connect_timeout']) ? (string) $data['connect_timeout'] : null);
        Setting::put('storage_read_timeout', isset($data['read_timeout']) ? (string) $data['read_timeout'] : null);
        Setting::put('storage_verify_tls', $request->boolean('verify_tls') ? '1' : '0');

        if ($request->filled('password')) {
            Setting::putEncrypted('storage_password', $data['password']);
        } elseif ($request->boolean('clear_password')) {
            Setting::putEncrypted('storage_password', null);
        }

        return redirect()->route('admin.storage.index')->with('success', 'Настройки файлового хранилища сохранены');
    }

    public function test(Request $request)
    {
        $data = $this->validateSettings($request);
        $config = StorageConfig::fromInput($data, StorageConfig::fromSettings());

        try {
            $driver = app(StorageDriverFactory::class, ['config' => $config])->make();
            $entries = $driver->list('');

            return back()->with('success', 'Подключение установлено: ' . $driver->displayRoot() . '. Элементов в корне: ' . count($entries) . '.');
        } catch (StorageException|StorageNotConfiguredException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable) {
            return back()->with('error', 'Не удалось подключиться к хранилищу. Проверьте адрес, логин и пароль.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSettings(Request $request): array
    {
        return $request->validate([
            'address' => [
                'required',
                'string',
                'max:1024',
                function (string $attribute, mixed $value, callable $fail) {
                    try {
                        StorageAddress::parse((string) $value);
                    } catch (InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:1024',
            'domain' => 'nullable|string|max:255',
            'clear_password' => 'sometimes|boolean',
            'connect_timeout' => 'nullable|integer|min:1|max:120',
            'read_timeout' => 'nullable|integer|min:1|max:600',
            'verify_tls' => 'sometimes|boolean',
        ]);
    }
}
