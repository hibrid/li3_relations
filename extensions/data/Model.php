<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2012, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_relations\extensions\data;
use \lithium\core\Libraries;
use \lithium\util\Inflector;
use \lithium\data\Connections;

class Model extends \lithium\data\Model {

	protected $originalRelations = array();

	protected $_alternateRelations = array();

	public static function __init(){
		parent::__init();
		self::_addRelations();
		self::_connectionFilters();
	}

	/**
	 * Creates a relationship binding between this model and another. Overwritten to allow model to model relations seperate of data source relations.
	 *
	 * @see lithium\data\model\Relationship
	 * @param string $type The type of relationship to create. Must be one of `'hasOne'`,
	 *               `'hasMany'` or `'belongsTo'`.
	 * @param string $name The name of the relationship. If this is also the name of the model,
	 *               the model must be in the same namespace as this model. Otherwise, the
	 *               fully-namespaced path to the model class must be specified in `$config`.
	 * @param array $config Any other configuration that should be specified in the relationship.
	 *              See the `Relationship` class for more information.
	 * @return object Returns an instance of the `Relationship` class that defines the connection.
	 */
	public static function bind($type, $name, array $config = array()) {
		$self = static::_object();

		if (!isset($config['to']) && isset($config['class'])) {
			$config['to'] = Libraries::locate('models', $config['class']);
			$targetModel = $config['to'];
		} else {
			$targetModel = Libraries::locate('models', $config['class']);
		}

		//TODO, add general exception option & add mongo exception for non embedded
		if(!empty($targetModel)){	

			// Mongo says it supports relationships, but it doesn't
			if(!isset($config['default'])){
				$connection = Connections::get($targetModel::meta('connection'), array('config' => true, 'autoCreate' => false));
				if($connection['type'] == 'MongoDb'){
					$config['default'] = false;

					$embeddedCheck = $targetModel::meta('embedded');

					// for use with mongodb embedded source
					if(!empty($embeddedCheck)){
						$config['default'] = true;
					}
				}
			}

			// if current Model & target join model are of same class, pass to generic lithium relation
			$connection = static::connection();

			if((!isset($config['default']) || $config['default'] == true) 
				&& $connection::enabled('relationships') == true 
				&& get_class($targetModel::connection()) == get_class($connection) 
				&& $relationship = parent::bind($type, $name, $config)){
					if(!empty($relationship)){
						return $relationship;
					}
			}

			// continue on if default lithium relationship will not work
			if(isset($config['fieldName'])){
				$fieldName = $config['fieldName'];
			} else {
				$fieldName = $name;
				if($type == 'hasMany'){
					$fieldName = Inflector::pluralize($name);
				} else {
					$fieldName = Inflector::singularize($name);					
				}
				$fieldName = Inflector::underscore($fieldName);
			}
			$key = "{$fieldName}_id";

			$from = get_called_class();
			$config += compact('type', 'name', 'key', 'from', 'fieldName');

			$relationship = $connection->invokeMethod('_instance', array('relationship', $config));
			if(!empty($relationship)){
				$self->_alternateRelations[$name] = $relationship;
				return null;
			}

		}
		return parent::bind($type, $name, $config);
	}

	/**
	 * Returns a list of models related to `Model`, or a list of models related
	 * to this model, but of a certain type. Overwritten to return all/original/alternate relations
	 *
	 * @param string $name A type of model relation.
	 * @param string $type Specify all, original, alternate
	 * @return array An array of relation types.
	 */
	public static function relations($name = null, $type = 'original') {
		if($type == 'all' || $type == 'alternate'){
			$self = static::_object();

			if($type == 'all'){
				$relations = array_merge($self->_relations, $self->_alternateRelations);
			} else {
				$relations = $self->_alternateRelations;
			}

			if (!$name) {
				return $relations;
			}

			if(isset($relations[$name])) {
				return $relations[$name];
			}
		}

		return parent::relations($name);
	}

	/**
	 * Filter for connection->read to take alternate relations and call a batch ::find on appropiate relation
	 */
	protected static function _connectionFilters(){
		$connection = static::connection();

		$connection->applyFilter('read', function($self, $params, $chain){	

			$data = $chain->next($self, $params, $chain);

			// check to see if there are any alternateRelations
			if(!empty($params) && isset($params['options']) && isset($params['options']['alternateWith']) && !empty($params['options']['alternateWith'])){
				$alternateRelations = $params['options']['model']::relations(null, 'alternate');

				if(!empty($alternateRelations)){

					foreach($params['options']['alternateWith'] as $key => $val){
						// TODO add support for 'Relation' => array(options)
						if (is_int($key)) {
							$relationKey = $val;
						} else {
							$relationKey = $key;
						}

						$relation = null;

						if(isset($alternateRelations[$relationKey])){
							// get options from relationship
							$relation = $alternateRelations[$relationKey]->data();

							if(!is_int($key) && !empty($val)){
							    $relation = array_merge_recursive($relations[$key]->data(), $val);							
							}
						}

						if(!empty($relation)) {
							$relationModel = $relation['to'];
							$searchAssociations = array();
							$searchValues = array();
							$from = (string)array_shift(array_keys($relation['key']));
							$to = (string)$relation['key'][$from];

							if(!empty($relation['fieldName'])){
								$field = $relation['fieldName'];
							} else {
								$field = $class;
							}

							// grab all ids from ids to create one batch query
							$records = array();
							if (method_exists($data, 'map')) {
								foreach($data as $k => $record){
									$records[$k] = $record->to('array');
								}
							} else {
								$records[] = $data->to('array');
							}

							foreach($records as $k => $record){
								if(!empty($record[$from])){
									$searchValue = (array)$record[$from];
									// type casting for MySQL - always returns strings ????????????
									if(method_exists($self, 'value')){
										$casted = $self->value(array($from => $searchValue));
										$searchValue = $casted[$from];					
									}	
									$searchValues = array_merge($searchValues, $searchValue);
									$searchAssociations[$k] = $searchValue;					
								} else {
									$searchAssociations[$k] = null;
								}
							}

							// if we have at least one id
							if(!empty($searchValues)){
								$searchValues = array_unique($searchValues);

								$relation['conditions'][$to] = $searchValues;
								$unsetSearchKey = false;
								if(is_array($relation['fields'])){
									if(!in_array($to, $relation['fields'])){
										$relation['fields'][] = $to;
										$unsetSearchKey = true;
									}
								} else {
									$relation['fields'] = null;
								}

								$relationalData = $relationModel::find('all', $relation);

								if(!empty($relationalData)){
									$results = array();
									foreach($relationalData as $item){
										if(isset($item->$to)){
											if(method_exists($item->$to, 'to')){
												$ids = $item->$to->to('array');
											} else {
												$ids = array((string)$item->$to);
											}
										}
										foreach($ids as $id){
											if(!empty($id)){
												if($unsetSearchKey === true && isset($item->$to)){
													unset($item->$to);
												}
												$results[$id][] = $item;
											}
										}
									}
								}	
							}
	
							// check to make sure we have at least one association
							if(!empty($searchAssociations) && isset($results)){
								foreach($searchAssociations as $itemKey => $value){

									if(isset($data[$itemKey])){

										// create an associationResult to hold all of this items related data
										$associationResult = array();

										if(!is_null($value) && isset($results)){
											if(is_array($value)){
												// sort values to populate in the order returned by mongo result
												$value = array_keys(array_intersect_key($results, array_fill_keys($value, null)));
												foreach($value as $searchKey){
													$searchKey = (string)$searchKey;
													if(isset($results[$searchKey])){
														$associationResult = array_merge($associationResult, $results[$searchKey]);
													}
												}

												// add some processing for grouping - this was added for mongo, may not be needed
												if(isset($relation['group'])){
													$groupedResult = array();
													foreach($associationResult as $k => $result){
														$comparison = array();
														foreach($relation['group'] as $group_item){
															if(isset($result->$group_item)){
																$comparison[] = $result->$group_item;
															} else {
																$comparison[] = null;
															}
														}
														if(!empty($comparison)){
															$groupedResult[$k] = json_encode($comparison);
														}
													}
													$groupedResult = array_unique($groupedResult);
													$associationResult = array_values(array_intersect_key($associationResult, $groupedResult));
												}
											}
										}

										$relationConnection = $relationModel::connection();	
										// check to see if we have a result && if relation type is hasOne, if so shift result to one element
										if(count($associationResult) > 0){
											if($relation['type'] == 'hasOne'){
												$associationResult = array_shift($associationResult);
											} else {
												$associationResult = $relationConnection->item($relationModel, $associationResult, array('class' => 'set'));											
											}
										// else if result is empty, create default empty response. hasMany defaults to connection collection & hasOne defaults to null - Same response as provided via ::find('all') vs ::find('first')
										} else {
											if($relation['type'] == 'hasOne'){
												$associationResult = null;
											} else {
												$associationResult = $relationConnection->item($relationModel, array(), array('class' => 'set'));											
											}
										}

										// finally, add the relation
								        if (method_exists($data, 'map')) {
											$data[$itemKey]->$field = $associationResult;
									    } else {
											$data->$field = $associationResult;
									    }

									}

								}

							}

						}

					}

				}

			}

			return $data;
		});
	}

	protected static function _addRelations(){
		$filter = function($self, $params, $chain) {
			$object = new $self();

			$alternateWithKeys = array();
			$relations = $self::relations(null, 'alternate');

			if(!empty($params['options']['with'])){	
				foreach($params['options']['with'] as $k => $v){
					// if key is name
					if((is_string($k) && isset($relations[$k])) || isset($relations[$v])){
						$params['options']['alternateWith'][$k] = $v;
						unset($params['options']['with'][$k]);
					}
				}
			}

		   	return $chain->next($self, $params, $chain);
		};
		
		self::applyFilter('find', $filter);
	}

}