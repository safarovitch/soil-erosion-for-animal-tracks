<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Update the application locale for the active session.
     */
    public function update(Request $request)
    {
        $availableLocales = config('app.available_locales', [config('app.locale')]);

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($availableLocales)],
        ]);

        $locale = $validated['locale'];

        $request->session()->put('locale', $locale);
        App::setLocale($locale);

        $path = resource_path("lang/{$locale}.json");
        $translations = File::exists($path)
            ? json_decode(File::get($path), true)
            : [];

        return response()->json([
            'locale' => $locale,
            'translations' => $translations,
        ]);
    }
}

