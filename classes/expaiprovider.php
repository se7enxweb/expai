<?php
/**
 * File containing the expAiProvider interface.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A model backend of expai. The request and the answer use the shape of the Anthropic Messages API, which every
 * other part of expai speaks: a provider for another API translates both ways. Built in: expAiAnthropicProvider.
 * Another: expai.ini [AISettings] Provider=custom, ProviderClass=<class>; the constructor receives the [AISettings]
 * values (expAiConfig::providerSettings()). See doc/extending.md.
 */
interface expAiProvider
{
    /**
     * @param array $request system (string), messages (list; earlier assistant turns as the provider returned them),
     *                       tools (list of { name, description, input_schema }, optional), tool_choice (optional),
     *                       output_schema (a JSON schema the answer text must follow, optional), max_tokens (optional)
     * @return array answer: content (list of blocks: text, tool_use, thinking ...), content_json (the content list as
     *               JSON exactly as received, to store and send back unchanged), stop_reason, model, usage (as the API
     *               reported it), tokens (normalised: input, output, cache_write, cache_read), http_status, retries,
     *               rate_limits (header => value); the last five optional
     *
     * @throws expAiException
     */
    public function send( array $request );

    /** @return string the configured model name */
    public function modelName();

    /** @return string the provider id stored with each request in the usage log (e.g. anthropic) */
    public function providerId();
}
