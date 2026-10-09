<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FocalPoint_EasyCoach_LTI_Plugin
{
    private static ?self $instance = null;

    private FocalPoint_EasyCoach_LTI_Configuration $configuration;

    private FocalPoint_EasyCoach_LTI_Key_Provider $key_provider;

    private FocalPoint_EasyCoach_LTI_REST_Controller $rest_controller;

    private ?FocalPoint_EasyCoach_LTI_Database $database = null;

    private ?FocalPoint_EasyCoach_LTI_User_Mapper $user_mapper = null;

    private ?FocalPoint_EasyCoach_LTI_OIDC_Launch_Service $launch_service = null;

    private ?FocalPoint_EasyCoach_LTI_OAuth_Token_Service $token_service = null;

    private ?FocalPoint_EasyCoach_LTI_Launch_Controller $launch_controller = null;

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->configuration = new FocalPoint_EasyCoach_LTI_Configuration();
        $this->key_provider  = new FocalPoint_EasyCoach_LTI_Key_Provider(
            $this->configuration->key_id(),
            $this->configuration->private_key_path(),
            ABSPATH
        );
        $this->rest_controller = new FocalPoint_EasyCoach_LTI_REST_Controller(
            $this->configuration,
            $this->key_provider
        );

        add_action('init', array($this, 'initialise_data_layer'), 1);
        add_action('rest_api_init', array($this->rest_controller, 'register_routes'));
        add_filter(
            'rest_pre_serve_request',
            array($this->rest_controller, 'serve_authorization_form'),
            10,
            4
        );
    }

    public function initialise_data_layer(): void
    {
        if ($this->database !== null) {
            return;
        }

        global $wpdb;

        $this->database = new FocalPoint_EasyCoach_LTI_Database($wpdb);
        $this->database->maybe_install();

        $store = new FocalPoint_EasyCoach_LTI_User_Map_Repository(
            $wpdb,
            $this->database->user_map_table()
        );

        $this->user_mapper = new FocalPoint_EasyCoach_LTI_User_Mapper(
            $store,
            $this->configuration->deployment_id()
        );

        $launch_store = new FocalPoint_EasyCoach_LTI_Launch_Repository(
            $wpdb,
            $this->database->table_names()
        );
        $jwt_builder = new FocalPoint_EasyCoach_LTI_JWT_Builder(
            $this->key_provider,
            $this->configuration->key_id()
        );

        $replay_store = new FocalPoint_EasyCoach_LTI_Assertion_Replay_Repository(
            $wpdb,
            $this->database->table_names()['oauth_assertions']
        );
        $tool_jwks_provider = new FocalPoint_EasyCoach_LTI_Tool_JWKS_Provider(
            $this->configuration->tool_jwks_url()
        );
        $assertion_verifier = new FocalPoint_EasyCoach_LTI_Client_Assertion_Verifier(
            $tool_jwks_provider
        );
        $this->token_service = new FocalPoint_EasyCoach_LTI_OAuth_Token_Service(
            $this->configuration,
            $assertion_verifier,
            $replay_store,
            $jwt_builder
        );

        $this->launch_service = new FocalPoint_EasyCoach_LTI_OIDC_Launch_Service(
            $this->configuration,
            $this->user_mapper,
            $launch_store,
            $jwt_builder
        );
        $this->launch_controller = new FocalPoint_EasyCoach_LTI_Launch_Controller(
            $this->configuration,
            $this->launch_service
        );
        $this->launch_controller->register_hooks();
        $this->rest_controller->set_launch_service($this->launch_service);
        $this->rest_controller->set_token_service($this->token_service);
    }

    public function user_mapper(): ?FocalPoint_EasyCoach_LTI_User_Mapper
    {
        return $this->user_mapper;
    }

    public function launch_controller(): ?FocalPoint_EasyCoach_LTI_Launch_Controller
    {
        return $this->launch_controller;
    }
}
