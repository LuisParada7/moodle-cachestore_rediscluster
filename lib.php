<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * RedisCluster Cache Store - Main library
 *
 * @package   cachestore_rediscluster
 * @copyright 2017 Blackboard Inc. (http://www.blackboard.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * RedisCluster Cache Store
 *
 * Forked from the cachestore_redis plugin.
 */
class cachestore_rediscluster extends cache_store implements cache_is_key_aware, cache_is_lockable, cache_is_configurable {

    const PURGEMODE_LAZY = 'lazy';
    const PURGEMODE_UNLINK = 'unlink'; // Redis4.0+ only.
    const PURGEMODE_DEL = 'del';

    /**
     * Name of this store.
     *
     * @var string
     */
    protected $name;

    /**
     * The definition hash, used for hash key
     *
     * @var string
     */
    protected $hash;

    /**
     * Flag for readiness!
     *
     * @var boolean
     */
    protected $isready = false;

    /**
     * Cache definition for this store.
     *
     * @var cache_definition
     */
    protected $definition = null;

    /**
     * Connection to Redis for this store.
     *
     * @var RedisCluster
     */
    protected $redis;

    /**
     * Connection config.
     *
     * @var array
     */
    protected $config;

    /**
     * How many times the next command called should be retried on error.
     *
     * @var int
     */
    protected $retrylimit = 0;

    /**
     * Determines if the requirements for this type of store are met.
     *
     * @return bool
     */
    public static function are_requirements_met() {
        return class_exists('RedisCluster');
    }

    /**
     * Determines if this type of store supports a given mode.
     *
     * @param int $mode
     * @return bool
     */
    public static function is_supported_mode($mode) {
        return ($mode === self::MODE_APPLICATION);
    }

    /**
     * Get the features of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        return self::SUPPORTS_DATA_GUARANTEE + self::DEREFERENCES_OBJECTS;
    }

    /**
     * Get the supported modes of this type of cache store.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_modes(array $configuration = array()) {
        return self::MODE_APPLICATION;
    }

    /**
     * Constructs an instance of this type of store.
     *
     * @param string $name
     * @param array $configuration
     */
    public function __construct($name, array $configuration = array()) {
        $this->name = $name;

        // During unit test purge, it goes off process and no config is passed.
        if (PHPUNIT_TEST && empty($configuration)) {
            // The name is important because it is part of the prefix.
            $this->name    = self::get_testing_name();
            $configuration = self::get_testing_configuration();
        } else if (empty($configuration['server'])) {
            return;
        }

        // Default values.
        $this->config = [
            'failover' => RedisCluster::FAILOVER_NONE,
            'persist' => false,
            'prefix' => '',
            'purgemode' => self::PURGEMODE_LAZY,
            'readtimeout' => 3.0,
            'serializer' => Redis::SERIALIZER_IGBINARY,
            'server' => null,
            'serversecondary' => null,
            'session' => false,
            'timeout' => 3.0,
        ];

        // Override defaults.
        foreach (array_keys($this->config) as $key) {
            if (!empty($configuration[$key])) {
                $this->config[$key] = $configuration[$key];
            }
        }

        $this->connect();
    }

    protected function connect() {
        try {
            $this->redis = $this->new_rediscluster();
        } catch (Exception $e) {
            if (empty($this->config['serversecondary'])) {
                $this->fatal_error();
            }
            $subsys = $this->config['session'] ? 'SESSION' : 'MUC';
            trigger_error($subsys.': Primary redis seed list failed, trying with fallback seed list ('.$e->getMessage().')', E_USER_WARNING);
            try {
                $this->redis = $this->new_rediscluster(false);
            } catch (Exception $e) {
                trigger_error($subsys.': Redis failure, message: '.$e->getMessage(), E_USER_WARNING);
                $this->fatal_error();
            }
        }
    }

    protected function fatal_error() {
        global $CFG;
        @header('HTTP/1.0 '.$CFG->fatalhttpstatus);
        echo "<p>Error: Cache store connection failed</p><p>Try again later</p>";
        exit(1);
    }

    /**
     * Create a new RedisCluster instance and connect to the cluster.
     *
     * @return RedisCluster
     */
    protected function new_rediscluster($primary = true) {
        $dsn = $primary ? $this->config['server'] : $this->config['serversecondary'];
        $servers = explode(',', $dsn);

        $this->isready = false;
        $prefix = $this->config['session'] ? $this->config['prefix'] : $this->config['prefix'].$this->name.'-';
        if ($redis = new RedisCluster(null, $servers, $this->config['timeout'], $this->config['readtimeout'], $this->config['persist'])) {
            $redis->setOption(Redis::OPT_SERIALIZER, $this->config['serializer']);
            $redis->setOption(Redis::OPT_PREFIX, $prefix);
            $redis->setOption(RedisCluster::OPT_SLAVE_FAILOVER, $this->config['failover']);
            $this->isready = true;
        }
        return $redis;
    }

    /**
     * See if we can ping a Redis server in the cluster
     *
     * @param string $server The specific server to ping.
     * @return bool
     */
    protected function ping($server) {
        try {
            if ($redis->ping($server) === false) {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Get the name of the store.
     *
     * @return string
     */
    public function my_name() {
        return $this->name;
    }

    /**
     * Initialize the store.
     *
     * @param cache_definition $definition
     * @return bool
     */
    public function initialise(cache_definition $definition) {
        $this->definition = $definition;
        $this->hash       = $definition->generate_definition_hash();
        return true;
    }

    /**
     * Determine if the store is initialized.
     *
     * @return bool
     */
    public function is_initialised() {
        return ($this->definition !== null);
    }

    /**
     * Set how many times the next command (and only the next command) should
     * attempt ito retry before giving up. This value is reset after every
     * successful command.
     *
     * @param int $limit
     * @return void
     */
    public function set_retry_limit($limit = null) {
        if ($limit === null || $limit != (int) $limit || $limit < 0) {
            $limit = 0;
        }
        $this->retrylimit = $limit;
    }

    public function command() {
        $args = func_get_args();
        $function = array_shift($args);

        if ($this->retrylimit < 0) {
            $this->retrylimit = 0;
        }

        $success = false;
        $lastexception = null;
        $result = null;

        while ($this->retrylimit >= 0) {
            $this->retrylimit--;
            try {
                $result = call_user_func_array([$this->redis, $function], $args);
                $success = true;
                break;
            } catch (Exception $e) {
                $lastexception = $e;
                // Always retry once on CLUSTERDOWN after a short delay.
                if (preg_match('#CLUSTERDOWN#', $e->getMessage())) {
                    $this->retrylimit--;
                    usleep(rand(100000, 200000));
                    try {
                        $result = call_user_func_array([$this->redis, $function], $args);
                        $success = true;
                        break;
                    } catch (Exception $e) {
                        $lastexception = $e;
                    }
                }
            }
        }
        $this->retrylimit = 0;

        if (!$success) {
            throw $lastexception;
        }

        return $result;
    }

    /**
     * Determine if the store is ready for use.
     *
     * @return bool
     */
    public function is_ready() {
        return $this->isready;
    }

    /**
     * Get the value associated with a given key.
     *
     * @param string $key The key to get the value of.
     * @return mixed The value of the key, or false if there is no value associated with the key.
     */
    public function get($key) {
        return $this->command('hGet', $this->hash, $key);
    }

    /**
     * Get the values associated with a list of keys.
     *
     * @param array $keys The keys to get the values of.
     * @return array An array of the values of the given keys.
     */
    public function get_many($keys) {
        $return = array_fill_keys($keys, false);
        if ($result = $this->command('hMGet', $this->hash, $keys)) {
            $return = array_merge($return, $result);
        }
        return $return;
    }

    /**
     * Set the value of a key.
     *
     * @param string $key The key to set the value of.
     * @param mixed $value The value.
     * @return bool True if the operation succeeded, false otherwise.
     */
    public function set($key, $value) {
        return ($this->command('hSet', $this->hash, $key, $value) !== false);
    }

    /**
     * Set the values of many keys.
     *
     * @param array $keyvaluearray An array of key/value pairs. Each item in the array is an associative array
     *      with two keys, 'key' and 'value'.
     * @return int The number of key/value pairs successfuly set.
     */
    public function set_many(array $keyvaluearray) {
        $pairs = [];
        foreach ($keyvaluearray as $pair) {
            $pairs[$pair['key']] = $pair['value'];
        }
        if ($this->command('hMSet', $this->hash, $pairs)) {
            return count($pairs);
        }
        return 0;
    }

    /**
     * Delete the given key.
     *
     * @param string $key The key to delete.
     * @return bool True if the delete operation succeeds, false otherwise.
     */
    public function delete($key) {
        return $this->command('hDel', $this->hash, $key) > 0;
    }

    /**
     * Delete many keys.
     *
     * @param array $keys The keys to delete.
     * @return int The number of keys successfully deleted.
     */
    public function delete_many(array $keys) {
        array_unshift($keys, $this->hash);
        array_unshift($keys, 'hDel');
        return call_user_func_array([$this, 'command'], $keys);
    }

    /**
     * Purges all keys from the store.
     *
     * @return bool
     */
    public function purge() {
        if ($this->config['purgemode'] == self::PURGEMODE_LAZY) {
            // DEL is not fast if the hash has a lot of child elements.
            // Rename the key instead, it can be cleaned up later.
            $prefix = $this->redis->getOption(Redis::OPT_PREFIX);
            $gcid = uniqid(mt_rand(), true);

            // Since the originating key has a prefix in front of it, we need to
            // include it inside the hash tag here for the hashslot calculation.
            $temp = "gc:tmp:{$gcid}:{{$prefix}{$this->hash}}";
            $this->set_retry_limit(1);
            $rename = $this->command('rename', $this->hash, $temp) !== false;
            $this->command('sadd', 'gc:hash', $temp);
            return $rename;
        } else if ($this->config['purgemode'] == self::PURGEMODE_UNLINK) {
            // This is not supported before Redis4.
            return $this->command('unlink', $this->hash) !== false;
        }
        return ($this->command('del', $this->hash) !== false);
    }

    /**
     * Cleans up after an instance of the store.
     */
    public function instance_deleted() {
        $this->purge();
        $this->redis->close();
        unset($this->redis);
    }

    public function close() {
        $this->redis->close();
        unset($this->redis);
    }

    /**
     * Creates an instance of the store for testing.
     *
     * @param cache_definition $definition
     * @return mixed An instance of the store, or false if an instance cannot be created.
     */
    public static function initialise_test_instance(cache_definition $definition) {
        if (!self::are_requirements_met()) {
            return false;
        }
        $config = get_config('cachestore_rediscluster');
        if (empty($config->test_server)) {
            return false;
        }
        $cache = new cachestore_rediscluster('RedisCluster test', ['server' => $config->test_server]);
        $cache->initialise($definition);

        return $cache;
    }

    /**
     * Determines if the store has a given key.
     *
     * @see cache_is_key_aware
     * @param string $key The key to check for.
     * @return bool True if the key exists, false if it does not.
     */
    public function has($key) {
        return $this->command('hExists', $this->hash, $key);
    }

    /**
     * Determines if the store has any of the keys in a list.
     *
     * @see cache_is_key_aware
     * @param array $keys The keys to check for.
     * @return bool True if any of the keys are found, false none of the keys are found.
     */
    public function has_any(array $keys) {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determines if the store has all of the keys in a list.
     *
     * @see cache_is_key_aware
     * @param array $keys The keys to check for.
     * @return bool True if all of the keys are found, false otherwise.
     */
    public function has_all(array $keys) {
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tries to acquire a lock with a given name.
     *
     * @see cache_is_lockable
     * @param string $key Name of the lock to acquire.
     * @param string $ownerid Information to identify owner of lock if acquired.
     * @return bool True if the lock was acquired, false if it was not.
     */
    public function acquire_lock($key, $ownerid) {
        return $this->command('setnx', $key, $ownerid);
    }

    /**
     * Checks a lock with a given name and owner information.
     *
     * @see cache_is_lockable
     * @param string $key Name of the lock to check.
     * @param string $ownerid Owner information to check existing lock against.
     * @return mixed True if the lock exists and the owner information matches, null if the lock does not
     *      exist, and false otherwise.
     */
    public function check_lock_state($key, $ownerid) {
        $result = $this->command('get', $key);
        if ($result === $ownerid) {
            return true;
        }
        if ($result === false) {
            return null;
        }
        return false;
    }

    /**
     * Releases a given lock if the owner information matches.
     *
     * @see cache_is_lockable
     * @param string $key Name of the lock to release.
     * @param string $ownerid Owner information to use.
     * @return bool True if the lock is released, false if it is not.
     */
    public function release_lock($key, $ownerid) {
        if ($this->check_lock_state($key, $ownerid)) {
            return ($this->command('del', $key) !== false);
        }
        return false;
    }

    /**
     * Creates a configuration array from given 'add instance' form data.
     *
     * @see cache_is_configurable
     * @param stdClass $data
     * @return array
     */
    public static function config_get_configuration_array($data) {
        return [
            'failover' => $data->failover,
            'persist' => $data->persist,
            'prefix' => $data->prefix,
            'purgemode' => $data->purgemode,
            'readtimeout' => $data->readtimeout,
            'serializer' => $data->serializer,
            'server' => $data->server,
            'serversecondary' => $data->serversecondary,
            'timeout' => $data->timeout,
        ];
    }

    /**
     * Sets form data from a configuration array.
     *
     * @see cache_is_configurable
     * @param moodleform $editform
     * @param array $config
     */
    public static function config_set_edit_form_data(moodleform $editform, array $config) {
        $data = [
            'failover' => RedisCluster::FAILOVER_NONE,
            'persist' => false,
            'prefix' => '',
            'purgemode' => self::PURGEMODE_LAZY,
            'readtimeout' => 3.0,
            'serializer' => Redis::SERIALIZER_IGBINARY,
            'server' => null,
            'serversecondary' => null,
            'timeout' => 3.0,
        ];

        // Override defaults.
        foreach (array_keys($data) as $key) {
            if (!empty($config[$key])) {
                $data[$key] = $config[$key];
            }
        }

        $editform->set_data($data);
    }

    public static function initialise_unit_test_instance(cache_definition $definition) {
        if (!self::are_requirements_met()) {
            return false;
        }
        if (!self::ready_to_be_used_for_testing()) {
            return false;
        }

        $store = new cachestore_rediscluster(self::get_testing_name(), self::get_testing_configuration());
        if (!$store->is_ready()) {
            return false;
        }
        $store->initialise($definition);

        return $store;
    }

    public static function ready_to_be_used_for_testing() {
        return defined('CACHESTORE_REDISCLUSTER_TEST_SERVER');
    }

    /**
     * Return configuration to use when unit testing.
     *
     * @return array
     * @throws coding_exception
     */
    public static function get_testing_configuration() {
        global $DB;

        if (!self::are_requirements_met()) {
            throw new coding_exception('RedisCluster cache store not setup for testing');
        }
        return [
            'server' => CACHESTORE_REDISCLUSTER_TEST_SERVER,
            'prefix' => $DB->get_prefix(),
        ];
    }

    /**
     * Get the name to use when unit testing.
     *
     * @return string
     */
    private static function get_testing_name() {
        return 'test_application';
    }
}
