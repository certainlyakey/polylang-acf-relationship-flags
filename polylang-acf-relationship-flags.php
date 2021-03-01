<?php
/*
  Plugin Name: ACF + Polylang Relationship Flags
  Plugin URI: https://github.com/certainlyakey
  GitHub URI: https://github.com/certainlyakey
  Description: Adds a tiny flag image corresponding to post/term language in ACF relationship and taxonomy fields.
  Version: 0.1
  Author: Aleksandr Beliaev
  Author URI: https://aleksandrbeliaev.work
  License: GPL v2 or later

  Requires PHP 5.6 or later
*/


function aprf_polylang_display_language_code_for_object( $type = 'post', $id = null ) {

  $output = '';

  if (
    // Polylang exists
    function_exists( 'pll_get_post_language' ) &&
    // ACF exists
    function_exists( 'acf_get_setting' ) &&
    is_array( pll_languages_list() ) &&
    // There's more than one language active
    count( pll_languages_list() ) > 1
  ) {

    // language slug
    $current_lang = '';

    if ( $type === 'post' ) {
      $current_lang = pll_get_post_language( $id );
      $translations_ids = pll_get_post_translations( $id );
    }
    if ( $type === 'term' ) {
      $current_lang = pll_get_term_language( $id );
      $translations_ids = pll_get_term_translations( $id );
    }

    if ( $current_lang ) {
      $lang_term = get_term_by( 'slug', $current_lang, 'language' );
      $pll_lang = new PLL_Language($lang_term);
      $pll_lang_description = maybe_unserialize($pll_lang->description);
      $flags_path = file_exists( WP_PLUGIN_DIR . '/polylang-pro/' ) ? WP_PLUGIN_DIR . '/polylang-pro/vendor/wpsyntex/polylang/flags/' : WP_PLUGIN_DIR . '/polylang/flags/';
      $flag_file = file_get_contents( $flags_path .  . $pll_lang_description['flag_code'] . '.png' );

      if ( $translations_ids ) {

        $translations = aprf_array_map_assoc( function( $lang, $translation_id ) use ( $type, $current_lang ) {
          $translation = [ $lang => '' ];

          if ( $lang !== $current_lang ) {
            if ( $type === 'post' ) {
              $translation = [ $lang => get_the_title( $translation_id ) ];
            }
            if ( $type === 'term' ) {
              $term = get_term( $translation_id );
              $translation = [ $lang => $term->name ];
            }
          }

          return $translation;
        }, $translations_ids);


        $translations_strings = array_map( function( $translation_title, $lang ) {
          $translation_string = '';

          if (!empty( $translation_title )) {
            $translation_string  = $translation_title .' (' . $lang . ')';
          }
          return $translation_string;
        }, array_values( $translations ), array_keys( $translations ) );

        // remove empty titles
        $translations_strings = array_values( array_diff( $translations_strings, array( 'null', '' ) ) );

        $flag_title_attr = '';
        if (!empty( $translations_strings )) {
          $flag_title_attr = ' title="' . __( 'Other translations: ','aprf' ) . implode( $translations_strings, '; ' ) . '"';
        }

      }

      if ( $flag_file ) {
        $output .= ' <img style="margin-left: 2px" alt="' . __( 'Flag', 'aprf' ) . '"' . $flag_title_attr . ' src="' . 'data:image/png;base64,' . base64_encode( $flag_file ) . '">';
      } else {
        $output .= ' (' . $slug . ')';
      }
    }
  }

  return $output;
}



function aprf_array_map_assoc(callable $f, array $a) {
  return array_merge(...array_map($f, array_keys($a), $a));
}



// Append edit post link to relationship fields in admin
function aprf_update_relationship_field_admin( $title, $post, $field, $post_id ) {

  if (
    function_exists( 'pll_is_translated_post_type' ) &&
    pll_is_translated_post_type( $post->post_type )
  ) {
    $title .= aprf_polylang_display_language_code_for_object( 'post', $post->ID );
  }

  // only show the edit link to admins
  if ( current_user_can( 'create_users' ) ) {
    $title .= sprintf('<a style="margin-left:4px; opacity:.7; font-size:11px; text-decoration:none; color:currentColor" href="%s" target="_blank">(%s)</a>', get_edit_post_link( $post->ID ), __( 'edit', 'aprf' ) );
  }

  return $title;
}

add_filter('acf/fields/relationship/result', 'aprf_update_relationship_field_admin', 10, 4);



// Append flag to post_object fields in admin
function aprf_update_postobject_field_admin( $title, $post, $field, $post_id ) {

  if (
    function_exists( 'pll_is_translated_post_type' ) &&
    pll_is_translated_post_type( $post->post_type )
  ) {
    $title .= aprf_polylang_display_language_code_for_object( 'post', $post->ID );
  }

  return $title;
}

add_filter('acf/fields/post_object/result', 'aprf_update_postobject_field_admin', 10, 4);



// Append Polylang language code to taxonomy field entries in admin
function aprf_append_language_code_to_taxonomy_field_admin( $args, $field ) {

  if (
    function_exists( 'pll_is_translated_taxonomy' ) &&
    pll_is_translated_taxonomy( $args['taxonomy'] )
  ) {
    $args['walker'] = new aprf_acf_taxonomy_field_walker( $field );
  }

  return $args;

}

add_filter('acf/fields/taxonomy/wp_list_categories', 'aprf_append_language_code_to_taxonomy_field_admin', 10, 2);



/*
 * acf_taxonomy_field_walker class from acf/core/fields/taxonomy.php
 * @see https://github.com/elliotcondon/acf/blob/befc63020abba7d0bce27fa2bc67fec0f999b13d/core/fields/taxonomy.php
 * a small change to add Polylang current language to the rendered labels
 * This file should not be formatted/beautified, to allow for easier review and integration of possible future changes in this class by the plugin author
*/

class aprf_acf_taxonomy_field_walker extends Walker
{
  // vars
  var $field = null,
    $tree_type = 'category',
    $db_fields = array ( 'parent' => 'parent', 'id' => 'term_id' );
  // construct
  function __construct( $field )
  {
    $this->field = $field;
  }

  // start_el
  function start_el( &$output, $term, $depth = 0, $args = array(), $current_object_id = 0)
  {

    $selected = in_array( $term->term_id, $this->field['value'] );

    if( $this->field['field_type'] == 'checkbox' )
    {
      $output .= '<li><label class="selectit"><input type="checkbox" name="' . $this->field['name'] . '" value="' . $term->term_id . '" ' . ($selected ? 'checked="checked"' : '') . ' /> ' . $term->name;
      $output .= aprf_polylang_display_language_code_for_object( 'term', $term->term_id );
      $output .= '</label>';
    }
    elseif( $this->field['field_type'] == 'radio' )
    {
      $output .= '<li><label class="selectit"><input type="radio" name="' . $this->field['name'] . '" value="' . $term->term_id . '" ' . ($selected ? 'checked="checkbox"' : '') . ' /> ' . $term->name;
      $output .= aprf_polylang_display_language_code_for_object( 'term', $term->term_id );
      $output .= '</label>';
    }
    elseif( $this->field['field_type'] == 'select' )
    {
      $indent = str_repeat("&mdash; ", $depth);
      $output .= '<option value="' . $term->term_id . '" ' . ($selected ? 'selected="selected"' : '') . '>' . $indent . $term->name;
      $output .= aprf_polylang_display_language_code_for_object( 'term', $term->term_id );
      $output .= '</option>';
    }

  }


  //end_el
  function end_el( &$output, $term, $depth = 0, $args = array() )
  {
    if( in_array($this->field['field_type'], array('checkbox', 'radio')) )
    {
      $output .= '</li>';
    }

    $output .= "\n";
  }


  // start_lvl
  function start_lvl( &$output, $depth = 0, $args = array() )
  {
    // indent
    //$output .= str_repeat( "\t", $depth);


    // wrap element
    if( in_array($this->field['field_type'], array('checkbox', 'radio')) )
    {
      $output .= '<ul class="children">' . "\n";
    }
  }

  // end_lvl
  function end_lvl( &$output, $depth = 0, $args = array() )
  {
    // indent
    //$output .= str_repeat( "\t", $depth);


    // wrap element
    if( in_array($this->field['field_type'], array('checkbox', 'radio')) )
    {
      $output .= '</ul>' . "\n";
    }
  }

}
