<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use App\Models\AiHelpConversation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class AiHelpMessage
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property array|null $sources
 * @property string|null $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $tokens_cache
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property AiHelpConversation $ai_help_conversation
 */
class AiHelpMessage extends Model
{
    protected $table = 'ai_help_messages';

    protected $casts = [
        'conversation_id' => 'int',
        'sources' => 'array',
        'tokens_in' => 'int',
        'tokens_out' => 'int',
        'tokens_cache' => 'int',
    ];

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'sources',
        'model',
        'tokens_in',
        'tokens_out',
        'tokens_cache',
    ];

    public function ai_help_conversation(): BelongsTo
    {
        return $this->belongsTo(AiHelpConversation::class, 'conversation_id');
    }
}
