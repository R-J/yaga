<?php if (!defined('APPLICATION')) exit();

/* Copyright 2013 Zachary Doll */

/**
 * Manages the building of a rules cache and is provides admin functions for
 * managing badges in the dashboard.
 *
 * @since 1.0
 * @package Yaga
 */
class RulesController extends Gdn_Controller {

    /**
     * @var array These objects will be created on instantiation and available via
     * $this->ObjectName
     */
    public $Uses = ['Form'];

    /**
     * Memory cache for getInteractionRules()
     * 
     * @var unobtainedCache
     */
    private static $interactionRulesCache = null;

    /**
     * May be used in the future.
     *
     * @since 1.0
     * @access public
     */
    public function initialize() {
        parent::initialize();
        $this->Application = 'Yaga';
    }

    /**
     * This checks the cache for current rule set and expires once a day.
     * It loads all php files in the rules folder and selects only those that
     * implement the 'YagaRule' interface.
     *
     * @return array Rules that are currently available to use. The class names
     * are keys and the friendly names are values.
     */
    public static function getRules() {
        $rules = Gdn::cache()->get('Yaga.Badges.Rules');

        // rule files must always be loaded
         foreach (glob(PATH_PLUGINS.'/yaga/library/rules/*.php') as $filename) {
             include_once $filename;
         }

        if ($rules === Gdn_Cache::CACHEOP_FAILURE) {
            $tempRules = [];
            foreach (get_declared_classes() as $className) {
                if (in_array('YagaRule', class_implements($className))) {
                    $rule = new $className();
                    $tempRules[$className] = $rule->name();
                }
            }

            // TODO: Don't reuse badge model?
            $model = Gdn::getContainer()->get(BadgeModel::class);
            $model->EventArguments['Rules'] = &$tempRules;
            $model->fireAs('Yaga')->fireEvent('AfterGetRules');

            asort($tempRules);
            if (empty($tempRules)) {
                $rules = dbencode(false);
            } else {
                $rules = dbencode($tempRules);
            }
            Gdn::cache()->store('Yaga.Badges.Rules', $rules, [Gdn_Cache::FEATURE_EXPIRY => Gdn::config('Yaga.Rules.CacheExpire', 86400)]);
        }

        return dbdecode($rules);
    }

    /**
     * This checks the cache for current rule set that can be triggered for a user
     * by another user. It loads all rules and selects only those that return true
     * on its `Interacts()` method.
     *
     * @return array Rules that are currently available to use that are interactive.
     */
    public static function getInteractionRules() {
        if (self::$interactionRulesCache === null) {
            $rules = Gdn::cache()->get('Yaga.Badges.InteractionRules');
            if ($rules === Gdn_Cache::CACHEOP_FAILURE) {
                $allRules = RulesController::getRules();

                $tempRules = [];
                foreach ($allRules as $className => $name) {
                    $rule = new $className();
                    if ($rule->interacts()) {
                        $tempRules[$className] = $name;
                    }
                }
                if (empty($tempRules)) {
                    $rules = dbencode(false);
                } else {
                    $rules = dbencode($tempRules);
                }

                Gdn::cache()->store('Yaga.Badges.InteractionRules', $rules, [Gdn_Cache::FEATURE_EXPIRY => Gdn::config('Yaga.Rules.CacheExpire', 86400)]);
            }

            self::$interactionRulesCache = dbdecode($rules);
        }
        
        return self::$interactionRulesCache;
    }

    /**
     * This creates a new rule object in a safe way and renders its criteria form.
     *
     * @param string $ruleClass
     */
    public function getCriteriaForm($ruleClass) {
        if (class_exists($ruleClass) && in_array('YagaRule', class_implements($ruleClass))) {
            $rule = new $ruleClass();
            $this->Form->setStyles('bootstrap');
            $formString = $rule->form($this->Form);
            $description = $rule->description();
            $name = $rule->name();

            $data = ['CriteriaForm' => $formString, 'RuleClass' => $ruleClass, 'Name' => $name, 'Description' => $description];
            $this->renderData($data);
        } else {
            $this->renderException(new Gdn_UserException(Gdn::translate('Yaga.Error.Rule404')));
        }
    }
}
