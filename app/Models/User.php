<?php

namespace App\Models;

use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens; 
use App\Models\Role;
use App\Models\Driver\Driver; 
use App\Models\Admin\Admin;
use App\Models\Parent\ParentModel;
use Filament\Models\Contracts\HasName;

class User extends Authenticatable implements HasName, Wallet
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasWallet; 

    protected $table = 'users';

    public $timestamps = true;

    protected $fillable = [
        'full_name',
        'email',
        'phone_number',
        'alternative_phone',
        'password',
        'password_hash',
        'avatar_url',
        'gender',
        'role_id',
        'is_active',
        'is_trusted',
        'created_by',
        'custom_permissions',
        'phone_verified',
        'email_verified_at',
        'last_login_at',
        'new_email_temporary',
        'pending_new_email',
        'email_change_pending',
    ];

    protected $hidden = [
        'password',
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active'            => 'boolean',
            'is_trusted'           => 'boolean',
            'email_verified_at'    => 'datetime',
            'last_login_at'        => 'datetime',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
            'deleted_at'           => 'datetime',
            'email_change_pending' => 'boolean',
        ];
    }

    public function setCustomPermissionsAttribute($value): void
    {
        // V2 schema manages permissions purely via roles & permission_role pivot table.
    }

    public function getCustomPermissionsAttribute(): ?array
    {
        return null;
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = $value;
    }

    public function setPasswordHashAttribute($value): void
    {
        $this->attributes['password'] = $value;
    }

    public function getPasswordHashAttribute(): ?string
    {
        return $this->attributes['password'] ?? null;
    }

    public function getAuthPassword()
    {
        return $this->password;
    }

    public function getPhoneVerifiedAttribute(): bool
    {
        return !empty($this->phone_number);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * للتوافق التام مع الشاشات والـ Eager Loading القديم: استدعاء $user->user يعيد نفس السجل
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    public function parentProfile()
    {
        return $this->hasOne(ParentModel::class, 'id', 'id');
    }

    public function parent()
    {
        return $this->hasOne(ParentModel::class, 'id', 'id');
    }

    public function driver(): HasMany|BelongsTo|\Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Driver::class, 'user_id');
    }
    
    public function admin()
    {
        return $this->hasOne(Admin::class, 'id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(\App\Models\Parent\Child::class, 'parent_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(\App\Models\Parent\Address::class, 'parent_id');
    }

    public function devices()
    {
        return $this->hasMany(UserDevice::class, 'user_id');
    }

    public function notifications()
    {
        return $this->morphMany(\App\Models\Shared\DatabaseNotification::class, 'notifiable')->latest();
    }

    public function unreadNotifications()
    {
        return $this->notifications()->whereNull('read_at');
    }

    public function readNotifications()
    {
        return $this->notifications()->whereNotNull('read_at');
    }

    public function getFilamentName(): string
    {
        return $this->full_name ?? 'مدير النظام';
    }

    // ============================================================
    // 🛡️ دوال التحقق من الأدوار والصلاحيات (RBAC Methods)
    // ============================================================

    /**
     * هل المستخدم مدير عام بصلاحيات كاملة؟
     */
    public function isSuperAdmin(): bool
    {
        if ((int) $this->role_id === 1) {
            return true;
        }

        $roleName = $this->role?->name;
        return $roleName === 'super_admin' || $roleName === 'admin';
    }

    /**
     * جلب كافة الصلاحيات الممنوحة للمستخدم (دمج صلاحيات الدور مع الصلاحيات المخصصة)
     */
    public function getAllPermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        $rolePerms = $this->role?->permissions ?? [];
        if (!is_array($rolePerms)) {
            $rolePerms = json_decode($rolePerms, true) ?? [];
        }

        // إذا كان الدور يحمل All
        if (in_array('*', $rolePerms, true) || isset($rolePerms['all'])) {
            return ['*'];
        }

        $customPerms = $this->custom_permissions ?? [];
        if (!is_array($customPerms)) {
            $customPerms = json_decode($customPerms, true) ?? [];
        }

        return array_values(array_unique(array_merge($rolePerms, $customPerms)));
    }

    /**
     * التحقق من امتلاك المستخدم لصلاحية محددة
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $permissions = $this->getAllPermissions();

        if (in_array('*', $permissions, true)) {
            return true;
        }

        // التحقق من الصلاحية المباشرة
        if (in_array($permission, $permissions, true)) {
            return true;
        }

        // التحقق من صلاحيات القسم الشاملة (مثال: 'financial.*' تطابق 'financial.manage_withdrawals')
        $parts = explode('.', $permission);
        if (count($parts) > 1) {
            $wildcard = $parts[0] . '.*';
            if (in_array($wildcard, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق من امتلاك المستخدم لأي من الصلاحيات الممررة
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق من امتلاك المستخدم لكافة الصلاحيات الممررة
     */
    public function hasAllPermissions(array $permissions): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }
}