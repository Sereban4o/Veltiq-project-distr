<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCounterpartiesFrom1C;
use App\Models\Setting;
use App\Extensions\Crm\Models\Company;
use App\Services\Integration\OneC\CounterpartyConfig;
use App\Services\Integration\OneC\CounterpartySyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CounterpartyController extends Controller
{
    public function __construct(
        private CounterpartySyncService $sync,
    ) {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        $query = Company::query()->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('full_name', "%{$search}%")
                    ->orWhereLike('inn', "%{$search}%");
            });
        }

        return view('admin.counterparties.index', [
            'companies' => $query->paginate(25)->withQueryString(),
            'search' => $search,
            'configured' => $this->sync->configured(),
            'syncedAt' => Company::whereNotNull('ref_1c')->max('synced_at'),
            'linkedCount' => Company::whereNotNull('ref_1c')->count(),
            'deletedCount' => Company::where('deleted_in_1c', true)->count(),
            'url' => Setting::get('one_c_counterparties_url') ?? (string) config('one_c.counterparties.url'),
            'login' => Setting::get('one_c_counterparties_login') ?? (string) config('one_c.counterparties.login'),
            'password_set' => (bool) Setting::get('one_c_counterparties_password'),
            'verify' => self::verifySetting(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'url' => 'nullable|string|max:1024',
            'login' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:1024',
            'clear_password' => 'sometimes|boolean',
            'verify_tls' => 'sometimes|boolean',
        ]);

        Setting::put('one_c_counterparties_url', ($data['url'] ?? '') !== '' ? $data['url'] : null);
        Setting::put('one_c_counterparties_login', ($data['login'] ?? '') !== '' ? $data['login'] : null);
        Setting::put('one_c_counterparties_verify', $request->boolean('verify_tls') ? '1' : '0');

        if ($request->filled('password')) {
            Setting::putEncrypted('one_c_counterparties_password', $data['password']);
        } elseif ($request->boolean('clear_password')) {
            Setting::putEncrypted('one_c_counterparties_password', null);
        }

        CounterpartyConfig::forget();

        return redirect()->route('admin.counterparties.index')->with('success', 'Настройки сервиса 1С сохранены');
    }

    private static function verifySetting(): bool
    {
        $verify = Setting::get('one_c_counterparties_verify');

        return $verify !== null ? $verify === '1' : (bool) config('one_c.counterparties.verify', true);
    }

    public function sync()
    {
        if (!$this->sync->configured()) {
            return redirect()->route('admin.counterparties.index')->with(
                'error',
                'Сервис 1С не настроен: задайте ONE_C_COUNTERPARTIES_URL, ONE_C_COUNTERPARTIES_LOGIN и ONE_C_COUNTERPARTIES_PASSWORD.',
            );
        }

        SyncCounterpartiesFrom1C::dispatch(Auth::id());

        return redirect()->route('admin.counterparties.index')->with(
            'success',
            'Загрузка контрагентов из 1С запущена в фоне. Обновите страницу через несколько минут.',
        );
    }
}
