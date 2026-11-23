<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DocumentLetterhead
 *
 * @property int $id
 * @property int|null $app_id
 * @property string|null $first_page_path
 * @property string|null $next_pages_path
 * @property string|null $logo_path
 * @property float|null $logo_height
 * @property string|null $font_regular_path
 * @property string|null $font_bold_path
 * @property string|null $font_italic_path
 * @property string|null $color_primary
 * @property string|null $color_secondary
 * @property string|null $color_text
 * @property array|null $margins
 * @property string|null $address_position
 * @property string|null $sender_line
 * @property array|null $sender
 * @property bool|null $pdfa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentLetterhead extends Model
{
    protected $table = 'document_letterheads';

    protected $casts = [
        'app_id' => 'int',
        'margins' => 'array',
        'sender' => 'array',
        'logo_height' => 'float',
        'pdfa' => 'bool',
    ];

    protected $fillable = [
        'app_id',
        'first_page_path',
        'next_pages_path',
        'logo_path',
        'logo_height',
        'font_regular_path',
        'font_bold_path',
        'font_italic_path',
        'color_primary',
        'color_secondary',
        'color_text',
        'margins',
        'address_position',
        'sender_line',
        'sender',
        'pdfa',
    ];
}
