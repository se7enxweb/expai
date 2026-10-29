<?php
/**
 * File containing the expAiException class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A failure of expai. getMessage() is safe to show to the editor (English source text, translated with
 * translated() in the context extension/expai/error); detail() is for the log only and never contains the API
 * key, the request or the answer.
 */
class expAiException extends Exception
{
    /** @var string */
    protected $detail;

    /** @var array the rate limit headers of the failed request (anthropic-ratelimit-*, retry-after), when there was one */
    public $rateLimits = array();

    /** @var array placeholders of the message, e.g. array( '%max' => 10 ) */
    protected $arguments;

    /**
     * @param string $message English text for the editor
     * @param string $detail  for the debug log
     * @param int $code       HTTP status of the provider, when there is one
     * @param array $arguments placeholders of $message
     */
    public function __construct( $message, $detail = '', $code = 0, array $arguments = array() )
    {
        parent::__construct( $message, (int) $code );
        $this->detail = (string) $detail;
        $this->arguments = $arguments;
    }

    /** @return string */
    public function detail()
    {
        return $this->detail;
    }

    /** @return array */
    public function arguments()
    {
        return $this->arguments;
    }

    /** @return string the message in the interface language */
    public function translatedMessage()
    {
        return ezpI18n::tr( 'extension/expai/error', $this->getMessage(), null, $this->arguments );
    }

    /**
     * The same failure with the message in the interface language (for ezjscore, which shows getMessage()).
     * @return expAiException
     */
    public function translated()
    {
        if ( $this->detail !== '' )
            eZDebug::writeWarning( $this->getMessage() . ' (' . $this->detail . ')', __METHOD__ );
        return new expAiException( $this->translatedMessage(), $this->detail, $this->getCode() );
    }
}
