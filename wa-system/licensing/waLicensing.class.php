<?php
/**
 * @example waLicensing::check('shop/plugins/referrals')->isPremium()
 * @since 2.7.0
 */
class waLicensing
{
    /**
     * Can be used to create subclasses limited to a certain product slug
     * @example
     *     class appLicensing {
     *         protected static $static_slug = 'app';
     *     }
     *     appLicensing::check()->isPremium()
     *
     * @var string
     */
    protected static $static_slug = null;

    /** @var string */
    protected $slug;
    protected $slug_parse = null;

    protected $license = null;

    protected static $cache = [];

    /** @param string */
    public function __construct($slug)
    {
        $this->slug = $slug;
        if (!isset(self::$cache[$slug])) {
            self::$cache[$slug] = $this;
        }
    }

    /**
     * @param string
     * @return waLicensing
     * @throws waException
     */
    public static function check($slug='')
    {
        $slug = ifempty($slug, static::$static_slug);
        if (empty($slug)) {
            throw new waException('$slug is required');
        }
        if (isset(self::$cache[$slug])) {
            return self::$cache[$slug];
        }
        return new static($slug);
    }

    /** @return string */
    public function getSlug()
    {
        return $this->slug;
    }

    /**
     * Whether product is in Standard (non-premium) mode.
     *
     * @return bool
     */
    public function isStandard()
    {
        return !self::isPremium();
    }

    /**
     * Whether product is in Premium mode. In this mode, more features are available to user.
     *
     * Premium mode is enabled if license allows it, or if app at any point in past
     * had a license with premium features enabled, or if any of premium features
     * are currently turned on.
     *
     * Installer occasionally checks licensing for all installed products independently
     * and will take measures in case of violation. It is not this class' job to force
     * any action or even try to outsmart a clever hacker.
     *
     * @return bool
     */
    public function isPremium()
    {
        // Used to have Premium license in the past?
        $is_premium = $this->getSetting('license_premium');
        if ($is_premium) {
            return true;
        }

        // If any premium feature is enabled, force Premium license mode.
        // Installer checks this occasionally and enforces licensing penalties.
        if (self::isAnyPremiumFeatureEnabled()) {
            return true;
        }

        // Ask Installer if we have a proper license.
        if (self::hasPremiumLicense()) {
            $this->setSetting('license_premium', date('Y-m-d H:i:s'));
            return true;
        }

        return false;
    }

    /**
     * @return bool whether Shop app has any premium feature turned on
     */
    public function isAnyPremiumFeatureEnabled()
    {
        try {
            list($app_id, $ext_id, $type) = $this->parseSlug();
            switch ($type) {
                case 'app':
                    return wa($app_id)->getConfig()->isAnyPremiumFeatureEnabled();
                case 'plugin':
                    return wa($app_id)->getPlugin($ext_id)->isAnyPremiumFeatureEnabled();
                case 'widget':
                case 'payment':
                case 'shipping':
                case 'theme':
                case 'sms':
                    return false; // not supported
            }
        } catch (waException $e) {
        }
        return false;
    }

    /**
     * @return bool whether installation has a proper license (basic or premium) bound to it
     */
    public function hasLicense()
    {
        $license = self::getLicense();
        return !empty($license['status']);
    }

    /**
     * @return bool whether installation has a license bound to it with Premium features enabled
     */
    public function hasPremiumLicense()
    {
        $license = self::getLicense();
        return ifempty($license, 'options', 'edition', null) === 'PREMIUM';
    }

    /**
     * @return bool whether installation has a basic (non-premium) license bound to it
     */
    public function hasStandardLicense()
    {
        return $this->hasLicense() && !$this->hasPremiumLicense();
    }

    /**
     * @return null|array
     */
    protected function getLicense()
    {
        if ($this->license === null) {
            $this->license = false;
            try {
                if (wa()->appExists('installer')) {
                    wa('installer');
                    $this->license = installerHelper::checkLicense($this->slug);
                }
            } catch (waException $e) {
            }
        }

        return ifempty($this->license);
    }

    protected function getSettingsProductKey()
    {
        list($app_id, $ext_id, $type) = $this->parseSlug();
        switch ($type) {
            case 'app':
                return $app_id;
            case 'plugin':
                return $app_id.'.'.$ext_id;
            case 'widget':
            case 'theme':
            case 'payment':
            case 'shipping':
            case 'sms':
                return "{$app_id}.{$type}.{$ext_id}";
        }
    }

    public function getSetting($name, $default=null)
    {
        $product_key = $this->getSettingsProductKey();
        if (!$product_key) {
            return; // not supported
        }
        return $this->getAppSettingModel()->get($product_key, $name, $default);
    }

    public function setSetting($name, $value)
    {
        $product_key = $this->getSettingsProductKey();
        if (!$product_key) {
            return; // not supported
        }
        if ($value !== null) {
            $this->getAppSettingModel()->set($product_key, $name, $value);
        } else {
            $this->getAppSettingModel()->del($product_key, $name);
        }
    }

    protected function getAppSettingModel()
    {
        static $app_settings_model = null;
        if ($app_settings_model === null) {
            $app_settings_model = new waAppSettingsModel();
        }
        return $app_settings_model;
    }

    protected function parseSlug()
    {
        if ($this->slug_parse === null) {
            if (wa()->appExists('installer')) {
                wa('installer');
                $this->slug_parse = installerHelper::parseSlug($this->slug);
            } else {
                $this->slug_parse = [null, null, 'unsupported'];
            }
        }
        return $this->slug_parse;
    }
}
