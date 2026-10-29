<?php
/**
 * File containing the expAiAltTextJobHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package expai
 */

/**
 * The job type expai.alttext (expAiAltText::queue(), the "Missing alt text" report): asks a vision model for the alt
 * text of one image and writes it into a new draft of the object, as the user who queued it. Never published.
 *
 * Before the call: the master switch (held while it is off), the budget (held when the reservation does not fit any
 * more), the data policy with the image rules (a model that meets it, else the job fails with the reason), an alt
 * text that was written meanwhile (done, nothing to do). The answer is kept in the checkpoint before the draft is
 * written, so a rerun after a crash never pays twice.
 */
class expAiAltTextJobHandler extends expQueueHandlerBase
{
    public function validate( array $payload )
    {
        if ( empty( $payload['object_id'] ) || empty( $payload['attribute_id'] ) || empty( $payload['language'] ) )
            return 'object_id, attribute_id and language are required';
        return null;
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        return ( isset( $p['label'] ) ? $p['label'] : 'Alt text' ) . ' (' . ( isset( $p['language'] ) ? $p['language'] : '' ) . ')';
    }

    public function run( expQueueJob $job, expQueueContext $context )
    {
        $p = $job->payloadArray();
        $config = expAiConfig::instance();
        $object = expAiObjects::fetch( (int) $p['object_id'] );
        if ( !$object instanceof eZContentObject )
            throw new expQueuePermanentException( 'the object ' . (int) $p['object_id'] . ' is gone', 'not_found' );
        $checkpoint = $context->checkpoint();
        if ( is_array( $checkpoint ) && isset( $checkpoint['answer'] ) )
            return $this->write( $config, $object, $p, $checkpoint['answer'] );

        if ( class_exists( 'expAiGate' ) && ( $off = expAiGate::switchedOff( $config, isset( $p['siteaccess'] ) ? (string) $p['siteaccess'] : null ) ) !== '' )
            return expQueueResult::hold( 'switched off: ' . $off );
        if ( !expAiAltText::enabled( $config ) )
            return expQueueResult::hold( 'alt text suggestions are switched off ([AltTextSettings] Enabled)' );

        // written meanwhile: nothing to do
        $attribute = eZContentObjectAttribute::fetch( (int) $p['attribute_id'], (int) $object->attribute( 'current_version' ) );
        if ( !$attribute || (string) $attribute->attribute( 'data_type_string' ) !== 'ezimage' )
            throw new expQueuePermanentException( 'the image field ' . (int) $p['attribute_id'] . ' is not in the current version', 'not_found' );
        if ( (string) $attribute->attribute( 'language_code' ) !== (string) $p['language'] )
        {
            $dataMap = $object->fetchDataMap( (int) $object->attribute( 'current_version' ), (string) $p['language'] );
            $attribute = null;
            foreach ( $dataMap as $candidate )
                if ( (int) $candidate->attribute( 'id' ) === (int) $p['attribute_id'] )
                    $attribute = $candidate;
            if ( !$attribute )
                throw new expQueuePermanentException( 'the object has no ' . $p['language'] . ' translation of the image field any more', 'not_found' );
        }
        if ( trim( (string) $attribute->attribute( 'content' )->attribute( 'alternative_text' ) ) !== '' )
            return expQueueResult::done( array( 'skipped' => 'the image has an alt text now' ), 'the image has an alt text now' );

        $budget = new expAiBudget( $config );
        $refusal = $budget->refusal( (int) $job->attribute( 'cost_reserved_micros' ), (int) $job->attribute( 'group_id' ), (int) $job->attribute( 'id' ) );
        if ( $refusal !== null )
            return expQueueResult::hold( 'budget: ' . $refusal['message'] );

        try
        {
            $choice = expAiAltText::chooseModel( $config, $object );
            $model = $config->registry()->getModel( $choice['alias'] );
            $lane = expAiLane::fetch( $model['provider'], 'standard', $config );
            $provider = $lane && $lane->usable() ? $lane->provider( $choice['alias'], $config ) : $config->registry()->providerFor( $choice['alias'] );
            $image = expAiImageInput::fromAttribute( $attribute, expAiImageInput::limitsFor( $config, $model['provider'] ),
                                                     (string) $config->get( 'AltTextSettings', 'ImageAlias', 'original' ) );
            $main = $object->mainNode();
            $recorder = new expAiUsageRecorder( $provider, array(
                'user_id' => (int) $job->attribute( 'user_id' ), 'node_id' => $main ? (int) $main->attribute( 'node_id' ) : 0, 'action' => 'queue.alt_text',
                'lane' => 'standard', 'job_uuid' => (string) $job->attribute( 'uuid' ), 'price_factor' => $lane ? $lane->factorPercent() : 100 ) );
            $context->heartbeat();
            $text = expAiAltText::userText( $config, $object, (string) $p['language'], (string) $attribute->contentClassAttribute()->attribute( 'name' ),
                                            expAiAltText::contextFromVersion( $object, (int) $object->attribute( 'current_version' ), (string) $p['language'] ) );
            $answer = expAiAltText::ask( $config, $recorder, $image, $text );
        }
        catch ( expAiVetoException $e )
        {
            return expQueueResult::fail( 'refused: ' . strtr( $e->getMessage(), $e->arguments() ), 'veto' );
        }
        catch ( expAiException $e )
        {
            $text = strtr( $e->getMessage(), $e->arguments() ) . ( $e->detail() !== '' ? ' (' . $e->detail() . ')' : '' );
            if ( in_array( (int) $e->getCode(), array( 429, 500, 502, 503, 529 ), true ) )
                return expQueueResult::retry( $text, 60 );
            return expQueueResult::fail( $text, $e->detail() === 'data policy' ? 'data_policy' : 'alttext' );
        }
        $micros = $answer['cost_amount'] === null ? 0 : expAiBudget::micros( $answer['cost_amount'] );
        $stored = array( 'value' => $answer['value'], 'decorative' => $answer['decorative'], 'note' => $answer['note'], 'model' => $answer['model'],
                         'alias' => $choice['alias'], 'provider' => $choice['provider'], 'image' => $answer['image'], 'tokens' => $answer['tokens'],
                         'cost_micros' => $micros, 'usage_log_id' => $answer['usage_log_id'] );
        $context->saveCheckpoint( array( 'answer' => $stored ) );
        $budget->settle( (int) $job->attribute( 'id' ), (int) $job->attribute( 'group_id' ), $micros );
        $context->cost( (int) $job->attribute( 'cost_actual_micros' ) + $micros );
        return $this->write( $config, $object, $p, $stored );
    }

    /**
     * Writes the stored answer into a new draft. A decorative image gets no draft: an empty alt text is the
     * editor's explicit choice.
     * @return expQueueResult
     */
    protected function write( expAiConfig $config, eZContentObject $object, array $p, array $answer )
    {
        $result = array( 'value' => $answer['value'], 'decorative' => (bool) $answer['decorative'], 'note' => $answer['note'], 'model' => $answer['model'],
                         'cost_micros' => (int) $answer['cost_micros'], 'image_tokens' => (int) $answer['image']['image_tokens'] );
        if ( $answer['value'] === '' )
            return expQueueResult::done( $result, 'no alt text suggested (decorative: the editor decides)' );
        try
        {
            $draft = expAiAltText::writeDraft( $config, $object, (string) $p['language'], (int) $p['attribute_id'], $answer['value'] );
        }
        catch ( expAiException $e )
        {
            return expQueueResult::fail( 'the draft could not be written: ' . strtr( $e->getMessage(), $e->arguments() ), 'draft' );
        }
        $version = $draft['version'];
        $main = $object->mainNode();
        expAiDraftLog::record( array(
            'contentobject_id' => (int) $object->attribute( 'id' ), 'version' => (int) $version->attribute( 'version' ),
            'user_id' => (int) eZUser::currentUserID(), 'node_id' => $main ? (int) $main->attribute( 'node_id' ) : 0,
            'parent_node_id' => (int) $version->attribute( 'main_parent_node_id' ), 'language_code' => (string) $draft['language'],
            'action' => 'alt_text', 'source' => 'queue', 'model' => (string) $answer['model'], 'provider' => (string) $answer['provider'],
            'prompt_summary' => expAiText::cut( 'Alt text: ' . $answer['value'], 200 ) ) );
        $result['draft_version'] = (int) $version->attribute( 'version' );
        $result['written'] = $draft['written'];
        return expQueueResult::done( $result, 'alt text written into draft version ' . (int) $version->attribute( 'version' ) . ( $answer['decorative'] ? ' (the model thinks the image is decorative)' : '' ) );
    }
}
