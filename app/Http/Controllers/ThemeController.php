<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Speichert Hell/Dunkel/System beim Benutzer, wenn er im Benutzermenü umschaltet. */
final class ThemeController
{
    public const array THEMES = ['light', 'dark', 'system'];

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $request->validate(['theme' => ['required', 'string', Rule::in(self::THEMES)]]);

        $user->forceFill(['theme' => $data['theme']])->save();

        return response()->json(['theme' => $data['theme']]);
    }
}
