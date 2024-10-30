<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Strings literal for Graph QL

// Operation name for graphql query to retrieve feature switches.
const GET_FEATURE_SWITCHES_OPERATION = 'GetFeatureSwitches';

/**
 * The graphql query to retrieve feature switches. This will be maintained backward compatible.
 */
const GET_FEATURE_SWITCHES_QUERY = <<<'GQL'
query GetFeatureSwitches($type: PluginType!, $version: String!) {
  plugin(type: $type, version: $version) {
    features {
      name
      value
      defaultValue
      rolloutPercentage
    }
  }
}
GQL;

