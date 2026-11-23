<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DocumentTemplate
 *
 * @property int $id
 * @property string $uuid
 * @property int $app_id
 * @property int $custom_table_id
 * @property string $name
 * @property string|null $recipient
 * @property string $body
 * @property string|null $file_name
 * @property string|null $language_field
 * @property string|null $file_field
 * @property string|null $email_to
 * @property string|null $email_subject
 * @property string|null $email_body
 * @property string|null $email_reply_to
 * @property array<string, mixed>|null $qr_bill
 * @property string $formats
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentTemplate extends Model
{
    protected $table = 'document_templates';

    protected $casts = [
        'app_id' => 'int',
        'custom_table_id' => 'int',
        'is_active' => 'bool',
        'sort_order' => 'int',
        'qr_bill' => 'array',
    ];

    protected $fillable = [
        'uuid',
        'app_id',
        'custom_table_id',
        'name',
        'recipient',
        'body',
        'file_name',
        'language_field',
        'file_field',
        'email_to',
        'email_subject',
        'email_body',
        'email_reply_to',
        'qr_bill',
        'formats',
        'is_active',
        'sort_order',
    ];
}
