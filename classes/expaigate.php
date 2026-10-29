<?php
/**
 * File containing the expAiGate and expAiVetoException classes.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * A request refused by the veto hook (a rule of [RequestVeto] or a listener of expai/request/veto). Queued requests
 * that meet one fail for good instead of being tried again.
 */
class expAiVetoException extends expAiException
{
}

/**
 * The master switch and the veto hook (doc/switch-and-veto.md): checked before every request expai sends to a
 * provider, and before a request is queued.
 *
 * The master switch: [AISettings] Enabled (off unless enabled/true) and [AISettings] DisabledSiteAccesses[] (the
 * siteaccesses on which expai is off; a queued request is checked against the siteaccess it was queued from). Off
 * means no tab, no edit form controls, no Vector, no field action, nothing queued, queued jobs held, no provider
 * call. A siteaccess can also set Enabled in its own settings/siteaccess/<siteaccess>/expai.ini.append.php.
 *
 * The veto hook: the rules of [RequestVeto] Rules[] ([RequestVeto_<rule>]: Sections[], Classes[], Subtrees[],
 * SiteAccesses[], Providers[], Regions[], Purposes[], Reason=), then the ezpEvent filter expai/request/veto. A
 * listener (site.ini [Event] Listeners[]=expai/request/veto@myClass::check) receives the request context and returns
 * it, with 'veto' set to a reason to refuse:
 *
 *   user (eZUser), user_id, node_id, object_id, class_identifier, section_id, path (node path string), siteaccess,
 *   provider, region, retention, model, model_alias, purpose (panel, vector, a field action, test, probe,
 *   queue.<purpose>), data_class (what the data policy requires of the content: regions, zero_retention), veto ('')
 *
 * The reason is shown to the editor and logged (a row of the usage log with error_class veto, and the debug log).
 * A listener that does not return the context refuses the request (fail closed).
 */
class expAiGate
{
    const EVENT = 'expai/request/veto';

    /** @return string the name of the current siteaccess ('' when there is none) */
    public static function siteAccess()
    {
        return isset( $GLOBALS['eZCurrentAccess']['name'] ) ? (string) $GLOBALS['eZCurrentAccess']['name'] : '';
    }

    /**
     * The master switch.
     * @param string|null $siteAccess the siteaccess to check (null: the current one)
     * @return string '' when expai is on, else the English text for the editor
     */
    public static function switchedOff( ?expAiConfig $config = null, $siteAccess = null )
    {
        $config = $config ?: expAiConfig::instance();
        if ( !$config->flag( 'AISettings', 'Enabled', false ) )
            return 'Exponential Intelligence is switched off.';
        $siteAccess = $siteAccess === null ? self::siteAccess() : (string) $siteAccess;
        if ( $siteAccess !== '' && in_array( $siteAccess, $config->arr( 'AISettings', 'DisabledSiteAccesses' ), true ) )
            return 'Exponential Intelligence is switched off on this site.';
        return '';
    }

    /** fetch( 'expai', 'switched_on' ): the master switch for the current siteaccess */
    public function fetchSwitchedOn()
    {
        return array( 'result' => self::switchedOff() === '' );
    }

    /**
     * The full context of a request from what the caller knows (user_id, node_id, provider, model, model_alias,
     * purpose, siteaccess).
     * @return array
     */
    public static function context( array $known, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $c = $known + array( 'user_id' => (int) eZUser::currentUserID(), 'node_id' => 0, 'object_id' => 0, 'provider' => '', 'model' => '',
                             'model_alias' => '', 'purpose' => '', 'siteaccess' => self::siteAccess() );
        $c['user_id'] = (int) $c['user_id'];
        $c['node_id'] = (int) $c['node_id'];
        $c['purpose'] = (string) $c['purpose'];
        $user = $c['user_id'] && $c['user_id'] === (int) eZUser::currentUserID() ? eZUser::currentUser() : ( $c['user_id'] ? eZUser::fetch( $c['user_id'] ) : null );
        $c['user'] = $user instanceof eZUser ? $user : null;
        $c += array( 'class_identifier' => '', 'section_id' => 0, 'path' => '', 'region' => '', 'retention' => '', 'data_class' => array() );
        $object = null;
        if ( $c['node_id'] > 0 )
        {
            $node = eZContentObjectTreeNode::fetch( $c['node_id'] );
            if ( $node instanceof eZContentObjectTreeNode )
            {
                $c['path'] = (string) $node->attribute( 'path_string' );
                $object = $node->object();
            }
        }
        if ( !$object && (int) $c['object_id'] > 0 )
            $object = expAiObjects::fetch( (int) $c['object_id'] );
        if ( $object instanceof eZContentObject )
        {
            $c['object_id'] = (int) $object->attribute( 'id' );
            $c['class_identifier'] = (string) $object->attribute( 'class_identifier' );
            $c['section_id'] = (int) $object->attribute( 'section_id' );
            if ( $c['path'] === '' && $object->mainNode() )
                $c['path'] = (string) $object->mainNode()->attribute( 'path_string' );
        }
        if ( $c['provider'] !== '' )
        {
            $settings = $config->registry()->getProvider( (string) $c['provider'] );
            if ( $settings )
            {
                $c['region'] = isset( $settings['Region'] ) ? (string) $settings['Region'] : '';
                $c['retention'] = isset( $settings['Retention'] ) ? (string) $settings['Retention'] : '';
            }
            if ( $object instanceof eZContentObject )
            {
                $requirement = expAiDataPolicy::forProvider( $config, (string) $c['provider'] )->requirement( $object );
                $c['data_class'] = array( 'regions' => $requirement['regions'], 'zero_retention' => (bool) $requirement['zero_retention'] );
            }
        }
        $c['veto'] = '';
        return $c;
    }

    /**
     * The veto of the rules and the listeners.
     * @return array( string reason ('' when allowed), string source (rule:<name> or listener) )
     */
    public static function veto( array $context, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        foreach ( $config->arr( 'RequestVeto', 'Rules' ) as $rule )
        {
            if ( !self::validRule( $config, $rule ) )
                continue;
            if ( self::ruleMatches( $config, $rule, $context ) )
            {
                $reason = trim( (string) $config->get( 'RequestVeto_' . $rule, 'Reason', '' ) );
                return array( $reason !== '' ? expAiText::cut( $reason, 200 ) : 'This request is not allowed on this site.', 'rule:' . $rule );
            }
        }
        $event = ezpEvent::getInstance();
        if ( $event->hasListeners( self::EVENT ) )
        {
            $result = $event->filter( self::EVENT, $context );
            if ( !is_array( $result ) )
                return array( 'A check of this site could not decide on this request.', 'listener returned no context' );
            if ( isset( $result['veto'] ) && is_scalar( $result['veto'] ) && trim( (string) $result['veto'] ) !== '' )
                return array( expAiText::cut( trim( (string) $result['veto'] ), 200 ), 'listener' );
        }
        return array( '', '' );
    }

    /**
     * The veto of the rules for one object a request reads (a tool's read, a list, a service result): the rules with a
     * condition on the content (Sections[], Classes[], Subtrees[]) are checked against the object (its section, class
     * and every location), the siteaccess, and what is known of the request (provider, region, purpose). A condition
     * on a part of the request that is not known counts as met: a rule that could refuse the object does (fail
     * closed); so does a condition on a fact of the content that is missing (no location, section or class). Rules
     * without a condition on the content concern the request as a whole and are checked by check().
     *
     * @param array $request siteaccess (default: the current one), provider, region, purpose
     * @return array( string reason ('' when allowed), string source (rule:<name>) )
     */
    public static function objectVeto( eZContentObject $object, array $request = array(), ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $paths = array();
        foreach ( (array) $object->assignedNodes( false ) as $node )
            $paths[] = (string) ( is_array( $node ) ? $node['path_string'] : $node->attribute( 'path_string' ) );
        $context = array( 'section_id' => (int) $object->attribute( 'section_id' ), 'class_identifier' => (string) $object->attribute( 'class_identifier' ),
                          'path' => implode( '', $paths ) );
        return self::contentVeto( $context, $request, $config );
    }

    /**
     * objectVeto() on the facts of the content: section_id, class_identifier, path (the path strings of every location
     * of the object, joined).
     * @return array( string reason, string source )
     */
    public static function contentVeto( array $content, array $request, expAiConfig $config )
    {
        $context = $content + array( 'siteaccess' => isset( $request['siteaccess'] ) ? (string) $request['siteaccess'] : self::siteAccess() );
        $unknown = array();
        foreach ( array( 'provider', 'region', 'purpose' ) as $key )
        {
            $context[$key] = isset( $request[$key] ) ? (string) $request[$key] : '';
            if ( $context[$key] === '' )
                $unknown[] = $key;
        }
        if ( $context['siteaccess'] === '' )
            $unknown[] = 'siteaccess';
        // the facts of the content fail closed as well: an object without a location (a new draft), without a
        // section or without a class meets the condition on it
        if ( !isset( $context['section_id'] ) || (int) $context['section_id'] <= 0 )
            $unknown[] = 'section_id';
        if ( !isset( $context['class_identifier'] ) || (string) $context['class_identifier'] === '' )
            $unknown[] = 'class_identifier';
        if ( !isset( $context['path'] ) || (string) $context['path'] === '' )
            $unknown[] = 'path';
        foreach ( $config->arr( 'RequestVeto', 'Rules' ) as $rule )
        {
            $group = 'RequestVeto_' . $rule;
            if ( !self::validRule( $config, $rule ) )
                continue;
            if ( !$config->arr( $group, 'Sections' ) && !$config->arr( $group, 'Classes' ) && !$config->arr( $group, 'Subtrees' ) )
                continue;
            if ( self::ruleMatches( $config, $rule, $context, $unknown ) )
            {
                $reason = trim( (string) $config->get( $group, 'Reason', '' ) );
                return array( $reason !== '' ? expAiText::cut( $reason, 200 ) : 'This request is not allowed on this site.', 'rule:' . $rule );
            }
        }
        return array( '', '' );
    }

    /** @return bool a valid rule of [RequestVeto] has a condition on the content (Sections[], Classes[], Subtrees[]) */
    public static function hasContentRules( expAiConfig $config )
    {
        foreach ( $config->arr( 'RequestVeto', 'Rules' ) as $rule )
        {
            $group = 'RequestVeto_' . $rule;
            if ( self::validRule( $config, $rule )
                 && ( $config->arr( $group, 'Sections' ) || $config->arr( $group, 'Classes' ) || $config->arr( $group, 'Subtrees' ) ) )
                return true;
        }
        return false;
    }

    /** @var array rule names already reported as invalid (one warning per process) */
    protected static $invalidRules = array();

    /**
     * @return bool the rule has a valid name and its group [RequestVeto_<rule>] exists; else it is skipped, with a
     *              warning in the debug log (once per process), so that a typing error is visible
     */
    public static function validRule( expAiConfig $config, $rule )
    {
        $rule = (string) $rule;
        if ( preg_match( '/^[A-Za-z0-9_]{1,40}$/', $rule ) && $config->ini()->hasGroup( 'RequestVeto_' . $rule ) )
            return true;
        if ( !isset( self::$invalidRules[$rule] ) )
        {
            self::$invalidRules[$rule] = true;
            eZDebug::writeWarning( "expai: [RequestVeto] Rules[]=$rule is skipped: " . ( preg_match( '/^[A-Za-z0-9_]{1,40}$/', $rule )
                ? "there is no group [RequestVeto_$rule]" : 'the name may only have letters, digits and _ (at most 40)' ), __METHOD__ );
        }
        return false;
    }

    /**
     * @param string[] $unknown keys of the context (siteaccess, provider, region, purpose, section_id, class_identifier,
     *                          path) whose value is not known: a condition on them counts as met
     * @return bool every condition the rule sets is met by the request (a rule without conditions meets every request)
     */
    public static function ruleMatches( expAiConfig $config, $rule, array $context, array $unknown = array() )
    {
        $group = 'RequestVeto_' . $rule;
        $lists = array( 'Sections' => (string) ( isset( $context['section_id'] ) ? $context['section_id'] : '' ),
                        'Classes' => isset( $context['class_identifier'] ) ? (string) $context['class_identifier'] : '',
                        'SiteAccesses' => isset( $context['siteaccess'] ) ? (string) $context['siteaccess'] : '',
                        'Providers' => isset( $context['provider'] ) ? (string) $context['provider'] : '',
                        'Regions' => isset( $context['region'] ) ? (string) $context['region'] : '' );
        $keys = array( 'Sections' => 'section_id', 'Classes' => 'class_identifier', 'SiteAccesses' => 'siteaccess', 'Providers' => 'provider',
                       'Regions' => 'region' );
        foreach ( $lists as $name => $value )
        {
            $allowed = $config->arr( $group, $name );
            if ( $allowed && in_array( $keys[$name], $unknown, true ) )
                continue;
            if ( $allowed && ( $value === '' || $value === '0' || !in_array( $value, $allowed, true ) ) )
                return false;
        }
        $subtrees = $config->arr( $group, 'Subtrees' );
        if ( $subtrees && !in_array( 'path', $unknown, true ) )
        {
            $path = isset( $context['path'] ) ? (string) $context['path'] : '';
            $inside = false;
            foreach ( $subtrees as $nodeID )
                if ( $path !== '' && is_numeric( $nodeID ) && strpos( $path, '/' . (int) $nodeID . '/' ) !== false )
                    $inside = true;
            if ( !$inside )
                return false;
        }
        $purposes = $config->arr( $group, 'Purposes' );
        if ( $purposes && !in_array( 'purpose', $unknown, true ) )
        {
            $purpose = isset( $context['purpose'] ) ? (string) $context['purpose'] : '';
            $hit = false;
            foreach ( $purposes as $pattern )
                if ( $pattern === $purpose || ( substr( $pattern, -1 ) === '*' && strpos( $purpose, substr( $pattern, 0, -1 ) ) === 0 ) )
                    $hit = true;
            if ( !$hit )
                return false;
        }
        return true;
    }

    /**
     * Checks a request: the master switch, then the veto. A veto is logged.
     * @return array the full context of the request
     * @throws expAiException when expai is switched off; expAiVetoException when the request is refused
     */
    public static function check( array $known, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $off = self::switchedOff( $config, isset( $known['siteaccess'] ) ? (string) $known['siteaccess'] : null );
        if ( $off !== '' )
        {
            // the audit trail (doc/audit-trail.md): a request the master switch refused
            if ( class_exists( 'expAiAudit' ) )
                expAiAudit::switchedOff( $known, $off === 'Exponential Intelligence is switched off.' ? 'site' : 'siteaccess' );
            // one literal per text, so that the translation files list each of them
            if ( $off === 'Exponential Intelligence is switched off.' )
                throw new expAiException( 'Exponential Intelligence is switched off.', 'master switch: [AISettings] Enabled' );
            throw new expAiException( 'Exponential Intelligence is switched off on this site.', 'master switch: [AISettings] DisabledSiteAccesses[]' );
        }
        $context = self::context( $known, $config );
        list( $reason, $source ) = self::veto( $context, $config );
        if ( $reason === '' )
            return $context;
        self::log( $context, $reason, $source );
        throw new expAiVetoException( 'This request was refused: %reason', 'veto (' . $source . ')', 403, array( '%reason' => $reason ) );
    }

    /** The prefix of the last_error of a queued job held by the master switch. */
    const HELD_PREFIX = 'switched off: ';

    /**
     * Resumes the queued jobs the master switch held, once expai is on again for the siteaccess each was queued
     * from (the cron part expai, cronjobs/expai_queue.php).
     * @return array resumed, still_held
     */
    public static function resumeHeld( ?expAiConfig $config = null, $limit = 500 )
    {
        $config = $config ?: expAiConfig::instance();
        $resumed = 0;
        $held = 0;
        if ( !expAiQueue::available() )
            return array( 'resumed' => 0, 'still_held' => 0 );
        $rows = (array) eZPersistentObject::fetchObjectList( expQueueJob::definition(), null,
            array( 'state' => 'held', 'last_error' => array( 'like', self::HELD_PREFIX . '%' ) ), array( 'id' => 'asc' ),
            array( 'offset' => 0, 'length' => max( 1, (int) $limit ) ), true );
        foreach ( $rows as $job )
        {
            $p = $job->payloadArray();
            if ( self::switchedOff( $config, isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : null ) !== '' )
            {
                ++$held;
                continue;
            }
            if ( expQueue::retry( $job ) )
                ++$resumed;
        }
        return array( 'resumed' => $resumed, 'still_held' => $held );
    }

    /** Records a refused request: a usage log row (error_class veto) and the debug log. */
    public static function log( array $context, $reason, $source )
    {
        eZDebug::writeNotice( 'expai: request refused (' . $source . '): ' . $reason . ' [purpose ' . $context['purpose'] . ', node ' . $context['node_id']
                              . ', provider ' . $context['provider'] . ']', __METHOD__ );
        try
        {
            expAiUsage::record( array( 'user_id' => (int) $context['user_id'], 'node_id' => (int) $context['node_id'], 'action' => (string) $context['purpose'],
                                       'provider' => (string) $context['provider'], 'model' => (string) $context['model'],
                                       'model_alias' => (string) $context['model_alias'], 'region' => (string) $context['region'],
                                       'retention' => (string) $context['retention'], 'http_status' => 0, 'error_class' => 'veto',
                                       'error_message' => 'refused (' . $source . '): ' . $reason, 'session_key' => expAiLimits::sessionKey() ) );
        }
        catch ( Throwable $e )
        {
            eZDebug::writeWarning( 'expai: the refused request could not be logged: ' . $e->getMessage(), __METHOD__ );
        }
    }
}
