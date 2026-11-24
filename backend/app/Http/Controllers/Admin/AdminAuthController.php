<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Security\AuthEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    public function showLoginForm()
    {
        return view('admin.login');
    }

    public function login(Request $request, AuthEventLogger $journal)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // The admin panel is expected to stay signed in on an operator's
        // workstation: issue a remember cookie so a lost/expired PHP session
        // does not silently kick them back to the login form mid-action.
        if (Auth::attempt($credentials, true)) {
            $request->session()->regenerate();

            $user = Auth::user();

            // Check if user is admin
            if (!$user->isAdmin()) {
                Auth::logout();
                $journal->failed($request, $credentials['email'], 'not_admin', 'blade');

                return back()->withErrors([
                    'email' => 'У вас нет прав администратора.',
                ]);
            }

            $journal->login($request, $user, 'blade');

            return redirect()->intended('/admin');
        }

        $journal->failed($request, $credentials['email'], 'invalid_credentials', 'blade');

        return back()->withErrors([
            'email' => 'Неверный email или пароль.',
        ])->onlyInput('email');
    }

    public function logout(Request $request, AuthEventLogger $journal)
    {
        $user = Auth::user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $journal->logout($request, $user, 'blade');

        return redirect('/admin/login');
    }
}
