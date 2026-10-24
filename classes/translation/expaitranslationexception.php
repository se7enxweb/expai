<?php
/**
 * File containing the expAiTranslationException class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A failure of a translation service. transient() says whether trying again later can help: a busy service (429),
 * a server error (5xx), a timeout or a connection that failed. A refused key (401, 403), a used-up quota (DeepL 456),
 * a language pair the service does not translate and a text whose placeholders came back wrong are permanent: the
 * queue does not retry them, and the translation's state becomes failed with the reason.
 */
class expAiTranslationException extends expAiException
{
    /** @var bool|null null: decided by the code */
    protected $transient;

    /** @var int seconds the service asked to wait (retry-after), 0 when it did not say */
    public $retryAfter = 0;

    /**
     * @var array what was translated before the failure (a later request of a batch failed): key (of the pieces),
     *            texts (piece index => text), billed (characters billed for them), cost_micros; empty when nothing
     */
    public $partial = array();

    /**
     * @param string $message English text for the editor
     * @param string $detail for the log
     * @param int $code the HTTP status, when there is one
     * @param array $arguments placeholders of $message
     * @param bool|null $transient null: 0, 408, 429 and 5xx are transient
     */
    public function __construct( $message, $detail = '', $code = 0, array $arguments = array(), $transient = null )
    {
        parent::__construct( $message, $detail, $code, $arguments );
        $this->transient = $transient === null ? null : (bool) $transient;
    }

    /** @return bool trying again later can help */
    public function transient()
    {
        return $this->transient !== null ? $this->transient : self::transientStatus( (int) $this->getCode() );
    }

    /** @return bool the status of a failure that may pass: none (transport), 408, 429, 5xx */
    public static function transientStatus( $status )
    {
        $status = (int) $status;
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * @return bool the failure of a translation service is transient. Only an expAiTranslationException can be (the
     *              services wrap transport errors and HTTP answers in one); any other exception (the gate, the limits,
     *              a bug) is not retried
     */
    public static function isTransient( Exception $e )
    {
        return $e instanceof self && $e->transient();
    }
}
