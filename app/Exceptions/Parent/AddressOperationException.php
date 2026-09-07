<?php

namespace App\Exceptions\Parent;

use Exception;

/**
 * استثناء أعمال خاص بعمليات دفتر العناوين.
 *
 * بدونه كانت أخطاء الأعمال (مثل «لا يمكن حذف عنوان مرتبط بطفل») تسقط في شبكة
 * الأمان العامة في bootstrap/app.php فتعود للمستخدم كـ 500 برسالة مبهمة.
 */
class AddressOperationException extends Exception
{
    protected string $errorCode;

    protected int $statusCode;

    protected array $context;

    public function __construct(
        string $message,
        string $errorCode = 'ADDRESS_OPERATION_FAILED',
        int $statusCode = 422,
        array $context = []
    ) {
        parent::__construct($message);

        $this->errorCode  = $errorCode;
        $this->statusCode = $statusCode;
        $this->context    = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
