<?php
/**
 * File containing the expAiLimitException class.
 *
 * @copyright Copyright CJW Network and Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A request refused by a limit of [Limits] (expAiLimits::check()): the limit is reached, would be passed together with
 * the requests under way, or cannot be kept for a model without a price. A queued job holds on it instead of failing.
 * The detail still starts with "limit ", as before.
 */
class expAiLimitException extends expAiException
{
}
