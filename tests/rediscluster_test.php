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
 * RedisCluster cache test.
 *
 * @package   cachestore_rediscluster
 * @copyright Copyright (c) 2015 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../../../tests/fixtures/stores.php');
require_once(__DIR__.'/../lib.php');

/**
 * RedisCluster cache test.
 *
 * @package   cachestore_rediscluster
 * @copyright Copyright (c) 2015 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_rediscluster_test extends cachestore_tests {
    /**
     * @var cachestore_rediscluster
     */
    protected $store;

    /**
     * Returns the MongoDB class name
     *
     * @return string
     */
    protected function get_class_name() {
        return 'cachestore_rediscluster';
    }

    protected function tearDown() {
        parent::tearDown();

        if ($this->store instanceof cachestore_rediscluster) {
            $this->store->purge();
        }
    }

    /**
     * @return cachestore_rediscluster
     */
    protected function create_cachestore_rediscluster() {
        /** @var cache_definition $definition */
        $definition = cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'cachestore_rediscluster', 'phpunit_test');
        $store      = cachestore_rediscluster::initialise_unit_test_instance($definition);

        $this->store = $store;

        if (!$store) {
            $this->markTestSkipped();
        }

        return $store;
    }

    public function test_has() {
        $store = $this->create_cachestore_rediscluster();

        $this->assertTrue($store->set('foo', 'bar'));
        $this->assertTrue($store->has('foo'));
        $this->assertFalse($store->has('bat'));
    }

    public function test_has_any() {
        $store = $this->create_cachestore_rediscluster();

        $this->assertTrue($store->set('foo', 'bar'));
        $this->assertTrue($store->has_any(array('bat', 'foo')));
        $this->assertFalse($store->has_any(array('bat', 'baz')));
    }

    public function test_has_all() {
        $store = $this->create_cachestore_rediscluster();

        $this->assertTrue($store->set('foo', 'bar'));
        $this->assertTrue($store->set('bat', 'baz'));
        $this->assertTrue($store->has_all(array('foo', 'bat')));
        $this->assertFalse($store->has_all(array('foo', 'bat', 'this')));
    }

    public function test_lock() {
        $store = $this->create_cachestore_rediscluster();

        $this->assertTrue($store->acquire_lock('lock', '123'));
        $this->assertTrue($store->check_lock_state('lock', '123'));
        $this->assertFalse($store->check_lock_state('lock', '321'));
        $this->assertNull($store->check_lock_state('notalock', '123'));
        $this->assertFalse($store->release_lock('lock', '321'));
        $this->assertTrue($store->release_lock('lock', '123'));
    }

    public function test_purge() {
        $store = $this->create_cachestore_rediscluster();

        $store->set('flange', 'pipe');
        $this->store->purge();
        $this->assertFalse($store->has('flange'));
        $store->set('flange', 'xxx');
        $this->assertTrue($store->has('flange'));
        $store->purge();
        $this->assertFalse($store->has('flange'));
    }
}
