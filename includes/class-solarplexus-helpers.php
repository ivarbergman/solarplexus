<?php

class Solarplexus_Helpers {
  public static function retrieve_block_configs() {

    $config_path = SPLX_PLUGIN_PATH . 'splx-config.json';

    $theme_config_path = get_stylesheet_directory() . '/splx-config.json';

    // Override with custom config from theme
    if(file_exists($theme_config_path)) {
      $config_path = $theme_config_path;
    }

    $json = file_get_contents( $config_path );
    $config_data = json_decode( $json, true );

    return $config_data;
  }

  public static function get_block_type_id($block_config) {
    $id = null;
    if(array_key_exists('id', $block_config)) {
      $id = $block_config['id'];
    }
    return $id;
  }

  public static function block_args($block_config, $block_attributes) {
    $block_type_id = self::get_block_type_id($block_config);
    // Set grid and item classes from attributes

    $classes_grid = [];
    $classes_item = [];
    $attrs_grid = [
      'cols' => [
        'class_base' => 'splx-grid--cols',
        'value' => $block_config['noOfGridCols'],
        'divider' => ''
      ]
    ];
    $attrs_item = [
      'cols' => [
        'class_base' => 'splx-gridItemPostPreview--cols',
        'value' => $block_config['noOfGridCols'],
        'divider' => ''
      ]
    ];

    foreach ($block_attributes as $key => $attribute) {
      if (array_key_exists($key, $attrs_grid)) {
        $attrs_grid[$key]['value'] = $attribute;
      }

      if (array_key_exists($key, $attrs_item)) {
        $attrs_item[$key]['value'] = $attribute;
      }
    }

    // Add listType and itemLayout
    // directly from config instead
    // of from block attributes.
    $attrs_grid['listType'] = [
      'class_base' => 'splx-grid--listType',
      'value' => $block_config['listType'],
      'divider' => '-'
    ];

    $attrs_item['itemLayout'] = [
      'class_base' => 'splx-gridItemPostPreview--layout',
      'value' => $block_config['itemLayout'],
      'divider' => '-'
    ];

    foreach ($attrs_grid as $attribute) {
      $classes_grid[] = sprintf('%s%s%s', $attribute['class_base'], $attribute['divider'], $attribute['value']);
    }

    foreach ($attrs_item as $key => $attribute) {
      $classes_item[] = sprintf('%s%s%s', $attribute['class_base'], $attribute['divider'], $attribute['value']);
    }

    // Query posts
    $args = [
      'post_status' => 'publish'
    ];

    $current_post_id = get_the_ID();

    if($current_post_id) {
			$args['post__not_in'] = array($current_post_id);
    }
    
    if (array_key_exists('postType', $block_attributes)) {
      $args['post_type'] = $block_attributes['postType'];
      $args['posts_per_page'] = $block_attributes['noOfPosts'];
      $args['orderby'] = 'date';
    } else {
      $args['post_type'] = 'any';
    }

    if (array_key_exists('order', $block_attributes)) {
      $args['order'] = $block_attributes['order'];
    }

    if (array_key_exists('taxonomy', $block_attributes) && array_key_exists('terms', $block_attributes) && !empty($block_attributes['terms'])) {
      $args['tax_query'] = [];
      $args['tax_query'][] = [
        'taxonomy' => $block_attributes['taxonomy'],
        'field' => 'term_id',
        'terms' => $block_attributes['terms']
      ];
    }

    if (array_key_exists('searchResults', $block_attributes)) {
      $args['post__in'] = wp_list_pluck($block_attributes['searchResults'], 'id');
      if(!array_key_exists('orderby', $args)) {
        $args['orderby'] = 'post__in';
      }
    }

    // TODO maybe add filter possibility on $args
    // here as well?

    $query = new WP_Query($args);

    $posts = apply_filters('splx_posts', $query->posts, $block_config, $block_attributes);

    return [
      'query' => $query->query,
      'posts' => $posts,
      'block_attributes' => $block_attributes,
      'classes_grid' => apply_filters('splx_grid_classes', self::block_classes($classes_grid), $block_config, $block_attributes),
      'classes_item' => apply_filters('splx_item_classes', self::block_classes($classes_item), $block_config, $block_attributes),
      'config' => $block_config
    ];
  }


  public static function template_loader($block_config, $args) {
    $block_type_id = self::get_block_type_id($block_config);
    $loaded_template = '';
    $sage_template = self::get_sage_template($block_config);
    if($sage_template) {
      return self::template_loader_sage($sage_template, $block_config, $args); 
    }

    $template = self::get_template($block_config);

    if ($template && file_exists($template)) {

      $validated_file = validate_file($template);
      if (0 === $validated_file) {
        ob_start();
        load_template($template, false, $args);
        $loaded_template = ob_get_clean();
        
      } else {
        error_log("Solarplexus: Unable to validate template path: \"$template\". Error Code: $validated_file.");
      }
    } else {
      error_log("Solarplexus: Unable to load template for: \"$block_type_id\". File not found.");
    }

    return $loaded_template;
  }

  private static function is_sage(){
    return class_exists('Roots\Sage\Container');
  }

  private static function block_classes($classes) {
    $classes = array_unique($classes);
    $classes = join(' ', $classes);
    $classes = ltrim($classes);

    return $classes;
  }

  private static function template_loader_sage($template, $block_config, $args) {
    // Get the Sage instance with
    // the blade binding
    // TODO: maybe move this container stuff
    // to separate function if needed elsewhere
    $container = Roots\Sage\Container::getInstance();
    $abstract = 'blade';
    $sage = $container->bound($abstract)
        ? $container->makeWith($abstract, [])
        : $container->makeWith("sage.{$abstract}", []);

    $block_type_id = self::get_block_type_id($block_config);

    // Get the rendered template as a string
    $loaded_template = $sage->render($template, $args);

    return $loaded_template;
  }

  private static function get_sage_template($block_config) {
    $block_type_id = self::get_block_type_id($block_config);
    $template = '';
    // If block id matches a custom blade
    // template in the current theme
    $path = sprintf(
      '%s/views/%s/%s.blade.php',
      get_stylesheet_directory(),
      SPLX_TEMPLATE_FOLDER,
      $block_type_id
    );
    if(self::is_sage() && file_exists($path)) {
      // Return template reference in Blade-ish format
      // instead of file path, i.e splx-templates.foo
      $template = sprintf('%s.%s', SPLX_TEMPLATE_FOLDER, $block_type_id);
    }

    return $template;
  }

  private static function get_template($block_config) {
    $block_type_id = self::get_block_type_id($block_config);
    $template = '';    

    // If block id matches a custom
    // regular PHP template in the current theme
    if(!$template) {
      $template = locate_template(
        sprintf(
          '%s/%s.php',
          SPLX_TEMPLATE_FOLDER,
          $block_type_id
        )
      );
    }

    // If nothing yet, use default templates provided
    // by the plugin. Hard-coded template names here
    // since a use case is to have a custom config
    // without creating your own templates.
    if (!$template) {
      if($block_config['type'] == "dynamic") {
        $template = sprintf(
          '%s%s/%s.php',
          SPLX_PLUGIN_PATH,
          SPLX_TEMPLATE_FOLDER,
          'latest-default'
        );
      } else if($block_config['type'] == "handpicked") {
        $template = sprintf(
          '%s%s/%s.php',
          SPLX_PLUGIN_PATH,
          SPLX_TEMPLATE_FOLDER,
          'handpicked-default'
        );
      }
      
    }

    return $template;
  }

  
}