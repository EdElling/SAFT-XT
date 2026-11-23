<?php

declare(strict_types=1);

/**
 * Created by Reliese Model.
 */

namespace App\Models\Base;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DatabaseConnection
 *
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $host
 * @property int $port
 * @property string $database
 * @property string $username
 * @property string|null $password
 * @property string|null $default_schema
 * @property string|null $ssl_mode
 * @property int $connect_timeout
 * @property int $statement_timeout
 * @property bool $is_read_only
 * @property bool $is_active
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_successful
 * @property string|null $last_test_message
 * @property string|null $known_schemas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DatabaseConnection extends Model
{
    protected $table = 'database_connections';

    protected $casts = [
        'port' => 'int',
        'connect_timeout' => 'int',
        'statement_timeout' => 'int',
        'is_read_only' => 'bool',
        'is_active' => 'bool',
        'last_tested_at' => 'datetime',
        'last_test_successful' => 'bool',
    ];

    protected $hidden = [
        'password',
    ];

    protected $fillable = [
        'name',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'default_schema',
        'ssl_mode',
        'connect_timeout',
        'statement_timeout',
        'is_read_only',
        'is_active',
        'last_tested_at',
        'last_test_successful',
        'last_test_message',
        'known_schemas',
    ];
}
