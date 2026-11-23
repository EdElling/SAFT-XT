<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\AiHelpMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Class AiHelpConversation
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $language
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 * @property Collection|AiHelpMessage[] $ai_help_messages
 */
class AiHelpConversation extends Model
{
    protected $table = 'ai_help_conversations';

    protected $casts = [
        'user_id' => 'int',
    ];

    protected $fillable = [
        'user_id',
        'title',
        'language',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ai_help_messages(): HasMany
    {
        return $this->hasMany(AiHelpMessage::class, 'conversation_id');
    }
}
