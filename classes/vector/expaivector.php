<?php
/**
 * File containing the expAiVector class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * Vector (expai/vector, doc/vector.md): what the workspace needs from the rest of expai. The models a thread may use
 * (#358 registry through expAiConfig::modelChoices(), i.e. only what expai/model_use allows), the lanes of a model
 * (expAiLane when lanes are installed, else "standard"), classes and languages for the scope picker, the key
 * bindings ([KeyBindings] + the user's preference expai_keybindings), and threads as the browser sees them.
 * Read fresh for every request (no static state: Velocity's workers serve many users).
 */
class expAiVector
{
    /** @return bool [VectorSettings] Enabled and the current user has expai/write and expai/vector */
    public static function allowed()
    {
        return expAiConfig::instance()->flag( 'VectorSettings', 'Enabled', true ) && expAiServerFunctions::hasPolicy( 'write' ) && expAiThreadAccess::hasVector();
    }

    /** @return array the model groups the user may choose (expAiConfig::modelChoices()) and the user's current model */
    public static function models( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return array( 'groups' => $config->modelChoices(), 'current' => $config->effectiveModel(), 'default' => $config->model() );
    }

    /** @return bool the user may use the model alias */
    public static function mayUseModel( $alias, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        return $alias === '' || isset( $config->allowedModels()[(string) $alias] );
    }

    /**
     * The lanes of a model for the lane picker: from expAiLane (lanes, batches and budgets) when installed, else the
     * standard lane only. Batch lanes are listed but marked: they run only approved plans, never a foreground turn.
     * @return array list of { name, label, usable, batch, factor }
     */
    public static function lanes( $alias, ?expAiConfig $config = null )
    {
        $out = array( array( 'name' => 'auto', 'label' => ezpI18n::tr( 'extension/expai/vector', 'Automatic' ), 'usable' => true, 'batch' => false, 'factor' => 100 ) );
        if ( class_exists( 'expAiLane' ) && method_exists( 'expAiLane', 'forModel' ) )
        {
            try
            {
                foreach ( expAiLane::forModel( (string) $alias, $config ) as $name => $lane )
                    $out[] = array( 'name' => (string) $name, 'label' => self::laneLabel( (string) $name ), 'usable' => (bool) $lane->usable(),
                                    'batch' => (bool) $lane->isBatch(), 'factor' => (int) $lane->factorPercent() );
            }
            catch ( Exception $e )
            {
                eZDebug::writeWarning( 'expai: the lanes of ' . $alias . ' could not be read: ' . $e->getMessage(), __METHOD__ );
            }
        }
        if ( count( $out ) === 1 )
            $out[] = array( 'name' => 'standard', 'label' => self::laneLabel( 'standard' ), 'usable' => true, 'batch' => false, 'factor' => 100 );
        return $out;
    }

    /** @return string a translated lane name */
    public static function laneLabel( $name )
    {
        $labels = array( 'standard' => ezpI18n::tr( 'extension/expai/vector', 'Standard' ), 'flex' => ezpI18n::tr( 'extension/expai/vector', 'Flex' ),
                         'batch' => ezpI18n::tr( 'extension/expai/vector', 'Batch' ), 'fast' => ezpI18n::tr( 'extension/expai/vector', 'Fast' ),
                         'priority' => ezpI18n::tr( 'extension/expai/vector', 'Priority' ) );
        return isset( $labels[$name] ) ? $labels[$name] : $name;
    }

    /** @return array the content classes for the scope picker: list of { identifier, name } by name */
    public static function classes()
    {
        $out = array();
        foreach ( (array) eZContentClass::fetchAllClasses( true, false, false ) as $class )
            $out[] = array( 'identifier' => (string) $class->attribute( 'identifier' ), 'name' => (string) $class->attribute( 'name' ) );
        usort( $out, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return $out;
    }

    /** @return array the site's languages: list of { code, name } */
    public static function languages()
    {
        $out = array();
        foreach ( (array) eZContentLanguage::fetchList() as $language )
            $out[] = array( 'code' => (string) $language->attribute( 'locale' ), 'name' => (string) $language->attribute( 'name' ) );
        return $out;
    }

    /* ------------------------------------------------------------- key bindings */

    /** @return array command => { keys, when } from [KeyBindings] */
    public static function defaultBindings( ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $keys = (array) $config->get( 'KeyBindings', 'Binding', array() );
        $when = (array) $config->get( 'KeyBindings', 'When', array() );
        $out = array();
        foreach ( $keys as $command => $binding )
            if ( preg_match( '/^[a-z_]{1,40}$/', (string) $command ) )
                $out[$command] = array( 'keys' => (string) $binding, 'when' => isset( $when[$command] ) ? (string) $when[$command] : 'vector' );
        return $out;
    }

    /** @return bool a well-formed key binding: modifiers + a KeyboardEvent.code, a single character, or two of them ("Escape Escape") */
    public static function validKeys( $keys )
    {
        $keys = (string) $keys;
        if ( $keys === '' || strlen( $keys ) > 40 )
            return false;
        foreach ( explode( ' ', $keys ) as $chord )
            if ( !preg_match( '/^((Mod|Ctrl|Alt|Shift|Meta)\+)*([A-Z][A-Za-z0-9]{1,20}|[^\sA-Za-z0-9+])$/', $chord ) )
                return false;
        return count( explode( ' ', $keys ) ) <= 2;
    }

    /** @return array the user's overrides (preference expai_keybindings): command => keys */
    public static function userBindings()
    {
        if ( !expAiConfig::instance()->flag( 'KeyBindings', 'UserOverrides', true ) )
            return array();
        $data = json_decode( (string) eZPreferences::value( 'expai_keybindings' ), true );
        $out = array();
        $defaults = self::defaultBindings();
        foreach ( is_array( $data ) ? $data : array() as $command => $keys )
            if ( isset( $defaults[$command] ) && self::validKeys( $keys ) )
                $out[$command] = (string) $keys;
        return $out;
    }

    /** @return array command => { keys, when, default } the effective bindings of the current user */
    public static function bindings()
    {
        $out = array();
        $user = self::userBindings();
        foreach ( self::defaultBindings() as $command => $binding )
            $out[$command] = array( 'keys' => isset( $user[$command] ) ? $user[$command] : $binding['keys'], 'when' => $binding['when'],
                                    'default' => $binding['keys'] );
        return $out;
    }

    /**
     * Stores the user's overrides; unknown commands and malformed keys are refused, a binding equal to the default
     * is dropped.
     * @param array $bindings command => keys ('' resets)
     * @return array the effective bindings
     */
    public static function saveBindings( array $bindings )
    {
        if ( !expAiConfig::instance()->flag( 'KeyBindings', 'UserOverrides', true ) )
            throw new expAiException( 'Changing the shortcuts is switched off on this site.' );
        $defaults = self::defaultBindings();
        $store = self::userBindings();
        foreach ( $bindings as $command => $keys )
        {
            if ( !isset( $defaults[$command] ) )
                throw new expAiException( 'Unknown command: %command', '', 0, array( '%command' => (string) $command ) );
            $keys = trim( (string) $keys );
            if ( $keys === '' || $keys === $defaults[$command]['keys'] )
            {
                unset( $store[$command] );
                continue;
            }
            if ( !self::validKeys( $keys ) )
                throw new expAiException( 'This shortcut cannot be used: %keys', '', 0, array( '%keys' => $keys ) );
            $store[$command] = $keys;
        }
        eZPreferences::setValue( 'expai_keybindings', $store ? json_encode( $store ) : '' );
        return self::bindings();
    }

    /* ----------------------------------------------------------- presentation */

    /** @return array a thread for the list (no node names: cheap for 100 rows) */
    public static function listRow( expAiThread $thread, $userID )
    {
        // the title is the owner's first request: an approver does not see it (it may name content)
        $title = $thread->isOwner( $userID ) ? (string) $thread->attribute( 'title' ) : '';
        $state = (string) $thread->attribute( 'state' );
        // a foreground run whose browser went away (closed tab, timed out request) never reports its end: after
        // [ThreadSettings] StaleRunSeconds without a change it is shown as stopped
        if ( in_array( $state, array( 'queued', 'running' ), true )
             && (int) $thread->attribute( 'modified' ) < time() - expAiConfig::instance()->int( 'ThreadSettings', 'StaleRunSeconds', 900, 60 ) )
            $state = 'stopped';
        return array(
            'uuid' => (string) $thread->attribute( 'uuid' ),
            'title' => $title !== '' ? $title : ezpI18n::tr( 'extension/expai/threads', 'New thread' ),
            'untitled' => $title === '',
            'withheld' => !$thread->isOwner( $userID ),
            'origin' => (string) $thread->attribute( 'origin' ),
            'node_id' => (int) $thread->attribute( 'node_id' ),
            'state' => $state,
            'shelf' => (string) $thread->attribute( 'shelf' ),
            'position' => (int) $thread->attribute( 'position' ),
            'run_mode' => (string) $thread->attribute( 'run_mode' ),
            'bulk' => $thread->isBulk(),
            'cost' => $thread->costText(),
            'tokens' => expAiCost::tokens( (int) $thread->attribute( 'tokens' ) ),
            'requests' => (int) $thread->attribute( 'requests' ),
            'modified' => (int) $thread->attribute( 'modified' ),
            'unread' => $thread->isOwner( $userID ) && (int) $thread->attribute( 'last_event_id' ) > (int) $thread->attribute( 'seen_event_id' ),
            'last_event_id' => (int) $thread->attribute( 'last_event_id' ),
            'is_owner' => $thread->isOwner( $userID ),
            'progress' => self::progressText( $thread ),
        );
    }

    /**
     * "3/8": the items of the thread's running plan that ended, of its items (doc/parallel-runs.md); '' when no plan
     * runs as child runs. Cheap: only for working threads with a plan.
     */
    public static function progressText( expAiThread $thread )
    {
        if ( !(int) $thread->attribute( 'plan_id' ) || !in_array( $thread->attribute( 'state' ), array( 'queued', 'running' ), true ) )
            return '';
        $runs = expAiThreadFanout::planRuns( expAiThreadFanout::planOf( $thread ) );
        if ( !$runs )
            return '';
        $done = count( array_filter( $runs, function ( $r ) { return !$r->isActive(); } ) );
        return $done . '/' . count( $runs );
    }

    /** @return array a thread with its scope names and settings (the header of the open thread) */
    public static function detail( expAiThread $thread, $userID, ?expAiConfig $config = null )
    {
        $config = $config ?: expAiConfig::instance();
        $row = self::listRow( $thread, $userID );
        $scope = $thread->scope();
        $nodes = function ( $ids )
        {
            $out = array();
            foreach ( $ids as $id )
            {
                $node = eZContentObjectTreeNode::fetch( (int) $id );
                $readable = $node instanceof eZContentObjectTreeNode && $node->canRead();
                $out[] = array( 'id' => (int) $id, 'name' => $readable ? (string) $node->attribute( 'name' ) : '#' . (int) $id,
                                'class' => $readable ? (string) $node->attribute( 'class_identifier' ) : '', 'readable' => $readable );
            }
            return $out;
        };
        $classNames = array();
        foreach ( $scope['classes'] as $identifier )
        {
            $class = eZContentClass::fetchByIdentifier( $identifier );
            $classNames[] = array( 'identifier' => $identifier, 'name' => $class ? (string) $class->attribute( 'name' ) : $identifier );
        }
        $languageNames = array();
        foreach ( $scope['languages'] as $code )
        {
            $language = eZContentLanguage::fetchByLocale( $code );
            $languageNames[] = array( 'code' => $code, 'name' => $language ? (string) $language->attribute( 'name' ) : $code );
        }
        $models = $config->allowedModels();
        $alias = (string) $thread->attribute( 'model_alias' );
        $row += array(
            'scope' => array( 'nodes' => $nodes( $scope['nodes'] ), 'subtrees' => $nodes( $scope['subtrees'] ), 'classes' => $classNames, 'languages' => $languageNames ),
            'review_policy' => (string) $thread->attribute( 'review_policy' ),
            'model_alias' => $alias,
            'model_label' => $alias !== '' ? ( isset( $models[$alias] ) ? $models[$alias] : $alias ) : '',
            'model_allowed' => $alias === '' || isset( $models[$alias] ),
            'lane' => (string) $thread->attribute( 'lane' ),
            'cost_micros' => (int) $thread->attribute( 'cost_micros' ),
            'created' => (int) $thread->attribute( 'created' ),
            'url' => 'expai/vector/(thread)/' . $thread->attribute( 'uuid' ),
            'panel_url' => $thread->attribute( 'origin' ) === expAiThread::ORIGIN_PANEL && (int) $thread->attribute( 'node_id' ) ? 'content/view/full/' . (int) $thread->attribute( 'node_id' ) : '',
            'can_write' => expAiThreadAccess::canWrite( $thread ),
            // staged review and plan mode (doc/review.md, doc/plan-mode.md)
            'changes' => expAiChange::counts( (int) $thread->attribute( 'id' ) ),
            'plan' => self::planSummary( $thread ),
            'reserved' => (int) $thread->attribute( 'reserved_micros' ) > 0 && expAiCost::enabled() ? expAiBudget::money( (int) $thread->attribute( 'reserved_micros' ) ) : '',
            // parallel runs of the plan (doc/parallel-runs.md): one row per item with state, progress, last step, cost
            'runs' => expAiThreadFanout::present( $thread ),
            // checkpoints (doc/checkpoints.md): the newest ones for the strip under the conversation
            'checkpoints' => expAiCheckpoint::listFor( $thread, 12 ),
        );
        if ( !$thread->isOwner( $userID ) )
        {
            $row['runs'] = self::visibleRuns( $row['runs'], false );
            $row['checkpoints'] = self::visibleCheckpoints( $row['checkpoints'], false );
        }
        return $row;
    }

    /** @return array|null the current plan of a thread in short: id, state, items, expected (money) */
    public static function planSummary( expAiThread $thread )
    {
        $plan = class_exists( 'expAiPlan' ) ? expAiPlan::fetchCurrent( (int) $thread->attribute( 'id' ) ) : null;
        if ( !$plan )
            return null;
        $estimate = $plan->estimate();
        return array( 'id' => (int) $plan->attribute( 'id' ), 'state' => (string) $plan->attribute( 'state' ), 'items' => count( $plan->runnableItems() ),
                      'expected' => isset( $estimate['expected_micros'] ) && expAiCost::enabled() ? expAiBudget::money( (int) $estimate['expected_micros'] ) : '' );
    }

    /**
     * The staged changes of a thread for the Changes tab: grouped by object (or proposed new object) and language,
     * fields in class order, each with its hunks (texts only) and whether its base changed since staging.
     * For a viewer who is not the owner, changes of objects the viewer may not read (for a new object: its parent
     * node) are left out and counted in $withheld (changeHidden()).
     * @return array list of { key, object_id, new_key, is_new, language, language_name, name, class_name, parent_name,
     *               base_version, conflict, fields: list of change rows + name, base_changed }
     */
    public static function changeGroups( expAiThread $thread, &$withheld = 0, $viewerID = null )
    {
        $owner = $thread->isOwner( $viewerID === null ? (int) eZUser::currentUserID() : (int) $viewerID );
        $groups = array();
        $languages = array();
        $hidden = array();
        $withheld = 0;
        foreach ( expAiChange::fetchForThread( (int) $thread->attribute( 'id' ) ) as $change )
        {
            $key = $change->targetKey();
            // a reviewer who may not read the object (or the parent of a new one) sees none of its values
            if ( !$owner && !isset( $groups[$key] ) && !isset( $hidden[$key] ) )
                $hidden[$key] = self::changeHidden( $change );
            if ( !empty( $hidden[$key] ) )
            {
                $withheld++;
                continue;
            }
            if ( !isset( $groups[$key] ) )
            {
                $code = (string) $change->attribute( 'language_code' );
                if ( !isset( $languages[$code] ) )
                {
                    $language = eZContentLanguage::fetchByLocale( $code );
                    $languages[$code] = $language ? (string) $language->attribute( 'name' ) : $code;
                }
                $class = eZContentClass::fetchByIdentifier( (string) $change->attribute( 'class_identifier' ) );
                $object = $change->isNew() ? null : expAiObjects::fetch( (int) $change->attribute( 'contentobject_id' ) );
                if ( $object && !$class )
                    $class = $object->contentClass();
                $parent = $change->isNew() ? eZContentObjectTreeNode::fetch( (int) $change->attribute( 'parent_node_id' ) ) : ( $object ? $object->mainNode() : null );
                if ( $parent && !$change->isNew() )
                    $parent = $parent->fetchParent();
                $values = $object && in_array( $code, array_keys( $object->allLanguages() ), true )
                        ? expAiDiff::values( $object, (int) $object->attribute( 'current_version' ), $code ) : array();
                $groups[$key] = array( 'key' => $key, 'object_id' => (int) $change->attribute( 'contentobject_id' ), 'new_key' => (string) $change->attribute( 'new_key' ),
                                       'is_new' => $change->isNew(), 'language' => $code, 'language_name' => $languages[$code],
                                       'name' => $object ? ( $object->canRead() ? (string) $object->attribute( 'name' ) : '#' . (int) $object->attribute( 'id' ) ) : '',
                                       'class_name' => $class ? (string) $class->attribute( 'name' ) : (string) $change->attribute( 'class_identifier' ),
                                       'parent_name' => $parent && $parent->canRead() ? (string) $parent->attribute( 'name' ) : '',
                                       'url' => $object && $object->mainNodeID() ? 'content/view/full/' . (int) $object->mainNodeID() : '',
                                       'conflict' => false, 'fields' => array(), '_values' => $values, '_class' => $class, '_exists' => $change->isNew() || (bool) $object );
            }
            $row = $change->present();
            $identifier = (string) $change->attribute( 'attribute_identifier' );
            $classAttribute = $groups[$key]['_class'] ? $groups[$key]['_class']->fetchAttributeByIdentifier( $identifier ) : null;
            $row['name'] = $classAttribute ? (string) $classAttribute->attribute( 'name' ) : $identifier;
            $row['position'] = $classAttribute ? (int) $classAttribute->attribute( 'placement' ) : 999;
            $row['base_changed'] = false;
            if ( !$change->isNew() && in_array( $row['decision'], array( 'pending', 'accepted', 'partial', 'conflict' ), true ) )
            {
                $current = isset( $groups[$key]['_values'][$identifier] ) ? (string) $groups[$key]['_values'][$identifier]['value'] : '';
                $row['base_changed'] = !$groups[$key]['_exists'] || expAiChange::hash( $current ) !== (string) $change->attribute( 'base_hash' );
            }
            if ( $row['decision'] === 'conflict' || $row['base_changed'] )
                $groups[$key]['conflict'] = true;
            if ( $change->isNew() && $groups[$key]['name'] === '' && in_array( $identifier, array( 'title', 'name', 'short_title', 'headline' ), true ) )
                $groups[$key]['name'] = expAiText::cut( strip_tags( (string) $change->attribute( 'proposed' ) ), 80 );
            $groups[$key]['fields'][] = $row;
        }
        $out = array();
        foreach ( $groups as $group )
        {
            unset( $group['_values'], $group['_class'], $group['_exists'] );
            usort( $group['fields'], function ( $a, $b ) { return $a['position'] - $b['position'] ?: $a['id'] - $b['id']; } );
            $out[] = $group;
        }
        return $out;
    }

    /** @return array the drafts the thread wrote, as the panel lists them (newest first) */
    public static function drafts( expAiThread $thread )
    {
        $out = array();
        $seen = array();
        $logs = eZPersistentObject::fetchObjectList( expAiDraftLog::definition(), null, array( 'thread_id' => (int) $thread->attribute( 'id' ) ),
                                                     array( 'id' => 'desc' ), array( 'offset' => 0, 'length' => 50 ) );
        foreach ( expAiDraftLog::markObjectsThatExist( (array) $logs ) as $log )
        {
            $key = $log->attribute( 'contentobject_id' ) . ':' . $log->attribute( 'version' );
            if ( isset( $seen[$key] ) || $log->attribute( 'status' ) === expAiDraftLog::STATUS_DISCARDED || $log->objectExists() === false )
                continue;
            $seen[$key] = true;
            if ( !self::objectReadable( (int) $log->attribute( 'contentobject_id' ) ) )
                continue;
            $row = expAiAssistant::draftRow( $log );
            if ( $row )
                $out[] = $row;
        }
        return $out;
    }

    /* ------------------------------------------------ what the viewer may read */

    /** @var array object id => bool, for the current user */
    protected static $readable = array();

    /** @return bool the current user may read the object (false when it does not exist) */
    public static function objectReadable( $objectID )
    {
        $objectID = (int) $objectID;
        $key = (int) eZUser::currentUserID() . ':' . $objectID;
        if ( !isset( self::$readable[$key] ) )
        {
            $object = $objectID ? expAiObjects::fetch( $objectID ) : null;
            self::$readable[$key] = $object instanceof eZContentObject && $object->canRead();
        }
        return self::$readable[$key];
    }

    /** @return bool the current user may read the node (false when it does not exist) */
    public static function nodeReadable( $nodeID )
    {
        $node = (int) $nodeID ? eZContentObjectTreeNode::fetch( (int) $nodeID ) : null;
        return $node instanceof eZContentObjectTreeNode && $node->canRead();
    }

    /** Forgets what objectReadable() found (the current user changed, or an object's rights). */
    public static function resetReadable()
    {
        self::$readable = array();
    }

    /**
     * A staged change the viewer may not see: an existing object (contentobject_id) the viewer may not read, or a new
     * object (contentobject_id 0) whose parent node (parent_node_id) the viewer may not read, or that has none.
     * @param callable|null $mayReadObject object id => bool (default objectReadable())
     * @param callable|null $mayReadNode node id => bool (default nodeReadable())
     * @return bool
     */
    public static function changeHidden( expAiChange $change, ?callable $mayReadObject = null, ?callable $mayReadNode = null )
    {
        $mayReadObject = $mayReadObject ?: array( 'expAiVector', 'objectReadable' );
        $mayReadNode = $mayReadNode ?: array( 'expAiVector', 'nodeReadable' );
        if ( !$change->isNew() )
            return !$mayReadObject( (int) $change->attribute( 'contentobject_id' ) );
        $parentID = (int) $change->attribute( 'parent_node_id' );
        return !$parentID || !$mayReadNode( $parentID );
    }

    /**
     * The items of a plan as a viewer who is not the owner may see them: an item whose target (target.object_id,
     * target.node_id, or target.parent_node_id of a new object, as expAiPlanner::newItem() stores them) the viewer may
     * not read, or whose target cannot be told, keeps its action and state, but not its name, instruction or fields.
     * @param array $items expAiPlan::items()
     * @param bool $owner the viewer owns the thread: the items are returned as they are
     * @param callable|null $mayReadObject object id => bool (default objectReadable())
     * @param callable|null $mayReadNode node id => bool (default nodeReadable())
     * @return array
     */
    public static function visiblePlanItems( array $items, $owner = false, ?callable $mayReadObject = null, ?callable $mayReadNode = null )
    {
        if ( $owner )
            return $items;
        $mayReadObject = $mayReadObject ?: array( 'expAiVector', 'objectReadable' );
        $mayReadNode = $mayReadNode ?: array( 'expAiVector', 'nodeReadable' );
        foreach ( $items as $i => $item )
        {
            $target = isset( $item['target'] ) && is_array( $item['target'] ) ? $item['target'] : array();
            $objectID = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;
            $nodeID = isset( $target['node_id'] ) ? (int) $target['node_id'] : 0;
            $parentID = isset( $target['parent_node_id'] ) ? (int) $target['parent_node_id'] : 0;
            if ( $objectID )
                $readable = (bool) $mayReadObject( $objectID );
            elseif ( $nodeID )
                $readable = (bool) $mayReadNode( $nodeID );
            elseif ( $parentID )
                $readable = (bool) $mayReadNode( $parentID );
            else
                // no target that can be checked: withheld (fail closed)
                $readable = false;
            if ( $readable )
                continue;
            $items[$i]['target_name'] = ( $objectID ?: ( $nodeID ?: $parentID ) ) ? '#' . ( $objectID ?: ( $nodeID ?: $parentID ) ) : '';
            $items[$i]['summary'] = '';
            $items[$i]['fields'] = array();
            $items[$i]['withheld'] = true;
        }
        return $items;
    }

    /**
     * The history of a conversation as a viewer who is not the owner may see it: the drafts of an exchange (key
     * "<object id>:<version>", as expAiAssistant stores them) on objects the viewer may not read, or whose key cannot
     * be read, are left out (with their names and the notes of what became of them).
     * @param array $history expAiAssistant::history()
     * @param bool $owner the viewer owns the thread: the history is returned as it is
     * @param callable|null $mayReadObject object id => bool (default objectReadable())
     * @return array
     */
    public static function visibleHistory( array $history, $owner = false, ?callable $mayReadObject = null )
    {
        if ( $owner )
            return $history;
        $mayReadObject = $mayReadObject ?: array( 'expAiVector', 'objectReadable' );
        // the turn records name the tools and link the requests of the log: not for an approver
        if ( isset( $history['records'] ) )
            $history['records'] = array();
        if ( empty( $history['exchanges'] ) || !is_array( $history['exchanges'] ) )
            return $history;
        foreach ( $history['exchanges'] as $i => $exchange )
        {
            // the words of the exchange (the owner's request, the answer, the steps with the names of what the tools
            // read) cannot be told apart by object: an approver does not see them (fail closed)
            foreach ( array( 'user', 'reply', 'snippet', 'user_snippet', 'notice' ) as $key )
                if ( isset( $history['exchanges'][$i][$key] ) )
                    $history['exchanges'][$i][$key] = '';
            if ( isset( $exchange['steps'] ) && is_array( $exchange['steps'] ) )
                foreach ( $exchange['steps'] as $n => $step )
                    $history['exchanges'][$i]['steps'][$n] = array( 'text' => '', 'ok' => !empty( $step['ok'] ), 'writes' => !empty( $step['writes'] ), 'withheld' => true );
            $history['exchanges'][$i]['texts_withheld'] = true;
            if ( empty( $exchange['drafts'] ) || !is_array( $exchange['drafts'] ) )
                continue;
            $kept = array();
            foreach ( $exchange['drafts'] as $draft )
                if ( is_array( $draft ) && isset( $draft['key'] ) && preg_match( '/^(\d+):(\d+)$/', (string) $draft['key'], $m ) && $mayReadObject( (int) $m[1] ) )
                    $kept[] = $draft;
            if ( count( $kept ) !== count( $exchange['drafts'] ) )
            {
                $history['exchanges'][$i]['drafts'] = $kept;
                // the notes of what became of the drafts name them: made again from the drafts that stay
                $history['exchanges'][$i]['outcomes'] = $kept ? expAiAssistant::outcomes( $kept ) : array();
                $history['exchanges'][$i]['withheld'] = count( $exchange['drafts'] ) - count( $kept );
            }
        }
        return $history;
    }

    /**
     * An event of a thread (expAiThreadEvent::present()) as a viewer who is not the owner may see it: the events that
     * name an object (draft, change, held) only when the viewer may read it (an event of a new object, or one whose
     * object cannot be told, is withheld); the words of the model and its tools (step_end, text, error) never, as
     * they cannot be told apart by object. The other kinds carry states, ids, counts and costs ($plainEventKeys).
     * @param bool $owner the viewer owns the thread: the event is returned as it is
     * @param callable|null $mayReadObject object id => bool (default objectReadable())
     * @return array
     */
    /** @var array kind => the payload keys an approver sees (the payloads of these kinds carry no content) */
    public static $plainEventKeys = array(
        'status' => array( 'state', 'from', 'held', 'run_id', 'applied', 'conflicts', 'runs', 'lane', 'job', 'switched' ),
        'cost' => array( 'micros', 'tokens', 'requests', 'run_id' ),
        'plan' => array( 'plan_id', 'items', 'refused', 'expected_micros', 'state', 'reserved_micros', 'actual_micros' ),
        'run' => array( 'run_id', 'item', 'state', 'micros', 'op', 'plan_id', 'runs', 'requests', 'lane', 'parallel' ),
        'checkpoint' => array( 'op', 'id', 'before', 'kind' ),
        'followup' => array( 'op', 'id' ),
    );

    public static function visibleEvent( array $event, $owner = false, ?callable $mayReadObject = null )
    {
        if ( $owner )
            return $event;
        $mayReadObject = $mayReadObject ?: array( 'expAiVector', 'objectReadable' );
        $kind = isset( $event['kind'] ) ? (string) $event['kind'] : '';
        $payload = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
        $runID = isset( $payload['run_id'] ) ? array( 'run_id' => (int) $payload['run_id'] ) : array();
        if ( in_array( $kind, array( 'draft', 'change', 'held' ), true ) )
        {
            $objectID = isset( $payload['object_id'] ) ? (int) $payload['object_id'] : 0;
            if ( !$objectID || !$mayReadObject( $objectID ) )
                $event['payload'] = array( 'withheld' => true ) + $runID;
            elseif ( $kind === 'held' && isset( $payload['fields'] ) && is_array( $payload['fields'] ) )
                // "held by run N (label)": the label of another run may name another object
                $event['payload']['fields'] = array_fill_keys( array_keys( $payload['fields'] ), 'held by another run' );
        }
        elseif ( $kind === 'step_end' )
            $event['payload'] = array( 'text' => '', 'ok' => !empty( $payload['ok'] ), 'writes' => !empty( $payload['writes'] ), 'withheld' => true ) + $runID;
        elseif ( in_array( $kind, array( 'text', 'error' ), true ) )
            $event['payload'] = array( 'withheld' => true ) + $runID;
        elseif ( isset( self::$plainEventKeys[$kind] ) )
            // states, ids, counts and costs only: a key this code does not know is left out (fail closed)
            $event['payload'] = array_intersect_key( $payload, array_flip( self::$plainEventKeys[$kind] ) );
        else
            // a kind this code does not know (or one with words: step_start, question, text_delta): withheld
            $event['payload'] = array( 'withheld' => true ) + $runID;
        return $event;
    }

    /**
     * The runs of a plan (expAiThreadFanout::present()) as a viewer who is not the owner may see them: no label (the
     * item's target and instruction) and no last step (the words of the model).
     * @return array
     */
    public static function visibleRuns( array $present, $owner = false )
    {
        if ( $owner || empty( $present['runs'] ) || !is_array( $present['runs'] ) )
            return $present;
        foreach ( $present['runs'] as $i => $run )
        {
            $present['runs'][$i]['label'] = '';
            $present['runs'][$i]['last'] = '';
            $present['runs'][$i]['withheld'] = true;
        }
        return $present;
    }

    /**
     * Checkpoints (expAiCheckpoint::listFor()) as a viewer who is not the owner may see them: no label (it is the
     * owner's request of the turn).
     * @return array
     */
    public static function visibleCheckpoints( array $checkpoints, $owner = false )
    {
        if ( $owner )
            return $checkpoints;
        foreach ( $checkpoints as $i => $checkpoint )
        {
            $checkpoints[$i]['label'] = '';
            $checkpoints[$i]['withheld'] = true;
        }
        return $checkpoints;
    }

    /**
     * A plan for the browser (expAiPlan::present()) as a viewer who is not the owner may see it: the items through
     * visiblePlanItems(), and no assumptions, questions or risks (the model's words).
     * @return array
     */
    public static function visiblePlan( array $plan, $owner = false, ?callable $mayReadObject = null, ?callable $mayReadNode = null )
    {
        if ( $owner )
            return $plan;
        $plan['items'] = self::visiblePlanItems( isset( $plan['items'] ) ? (array) $plan['items'] : array(), false, $mayReadObject, $mayReadNode );
        $plan['meta'] = array( 'withheld' => true );
        if ( isset( $plan['estimate']['meta'] ) )
            $plan['estimate']['meta'] = array( 'withheld' => true );
        return $plan;
    }

    /**
     * The waiting follow-ups of a thread: the owner gets them (with their text), a viewer who is not the owner only
     * how many wait (the text is the owner's message).
     * @param expAiFollowup[] $followups
     * @return array list (expAiFollowup::present() rows, empty for others), count
     */
    public static function visibleFollowups( array $followups, $owner = false )
    {
        $list = array();
        if ( $owner )
            foreach ( $followups as $followup )
                $list[] = $followup->present();
        return array( 'list' => $list, 'count' => count( $followups ) );
    }
}
