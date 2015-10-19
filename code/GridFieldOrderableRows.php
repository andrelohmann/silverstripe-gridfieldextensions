<?php
/**
 * Allows grid field rows to be re-ordered via drag and drop. Both normal data
 * lists and many many lists can be ordered.
 *
 * If the grid field has not been sorted, this component will sort the data by
 * the sort field.
 */
class GridFieldOrderableRows extends RequestHandler implements
	GridField_ColumnProvider,
	GridField_DataManipulator,
	GridField_HTMLProvider,
	GridField_URLHandler {
        
        private static $allowed_actions = array(
            'handleReorder',
            'handleMoveToPage'
        );

	/**
	 * The database field which specifies the sort, defaults to "Sort".
	 *
	 * @see setSortField()
	 * @var string
	 */
	protected $sortField;

	/**
	 * @param string $sortField
	 */
	public function __construct($sortField = 'Sort') {
		$this->sortField = $sortField;
	}

	/**
	 * @return string
	 */
	public function getSortField() {
		return $this->sortField;
	}

	/**
	 * Sets the field used to specify the sort.
	 *
	 * @param string $sortField
	 * @return GridFieldOrderableRows $this
	 */
	public function setSortField($field) {
		$this->sortField = $field;
		return $this;
	}

	/**
	 * Gets the table which contains the sort field.
	 *
	 * @param DataList $list
	 * @return string
	 */
	public function getSortTable(SS_List $list) {
		$field = $this->getSortField();

		if($list instanceof ManyManyList) {
			$extra = $list->getExtraFields();
			$table = $list->getJoinTable();

			if($extra && array_key_exists($field, $extra)) {
				return $table;
			}
		}

		$classes = ClassInfo::dataClassesFor($list->dataClass());

		foreach($classes as $class) {
			if(singleton($class)->hasOwnTableDatabaseField($field)) {
				return $class;
			}
		}

		throw new Exception("Couldn't find the sort field '$field'");
	}

	public function getURLHandlers($grid) {
		return array(
			'POST reorder'    => 'handleReorder',
			'POST movetopage' => 'handleMoveToPage'
		);
	}

	/**
	 * @param GridField $field
	 */
	public function getHTMLFragments($field) {
		GridFieldExtensions::include_requirements();

		$field->addExtraClass('ss-gridfield-orderable');
		$field->setAttribute('data-url-reorder', $field->Link('reorder'));
		$field->setAttribute('data-url-movetopage', $field->Link('movetopage'));
	}

	public function augmentColumns($grid, &$cols) {
		if(!in_array('Reorder', $cols) && $grid->getState()->GridFieldOrderableRows->enabled) {
			array_unshift($cols, 'Reorder');
		}
	}

	public function getColumnsHandled($grid) {
		return array('Reorder');
	}

	public function getColumnContent($grid, $record, $col) {
		return ViewableData::create()->renderWith('GridFieldOrderableRowsDragHandle');
	}

	public function getColumnAttributes($grid, $record, $col) {
		return array('class' => 'col-reorder');
	}

	public function getColumnMetadata($grid, $col) {
		return array('title' => '');
	}

	public function getManipulatedData(GridField $grid, SS_List $list) {
		$state = $grid->getState();
		$sorted = (bool) ((string) $state->GridFieldSortableHeader->SortColumn);

		// If the data has not been sorted by the user, then sort it by the
		// sort column, otherwise disable reordering.
		$state->GridFieldOrderableRows->enabled = !$sorted;

		if(!$sorted) {
			return $list->sort($this->getSortField());
		} else {
			return $list;
		}
	}

	/**
	 * Handles requests to reorder a set of IDs in a specific order.
	 */
	public function handleReorder($grid, $request) {
		if(!singleton($grid->getModelClass())->canEdit()) {
			$this->httpError(403);
		}

		$ids   = $request->postVar('order');
		$list  = $grid->getList();
		$field = $this->getSortField();
                
                if($list instanceof ManyManyList){
                    $owner = $grid->Form->getRecord();
                    list($parentClass, $componentClass, $parentField, $componentField, $table) = $owner->many_many($grid->getName());
                    $many_many = ' AND "' . $parentField . '" = ' . $owner->ID;
                }else{
                    $many_many = '';
                }

		if(!is_array($ids)) {
			$this->httpError(400);
		}

		$items = $list->byIDs($ids)->sort($field);

		// Ensure that each provided ID corresponded to an actual object.
		if(count($items) != count($ids)) {
			$this->httpError(404);
		}

		// Populate each object we are sorting with a sort value.
		$this->populateSortValues($items, $many_many);

		// Generate the current sort values.
		$current = $items->map('ID', $field)->toArray();

		// Perform the actual re-ordering.
		$this->reorderItems($list, $current, $ids, $many_many);

		return $grid->FieldHolder();
	}

	/**
	 * Handles requests to move an item to the previous or next page.
	 */
	public function handleMoveToPage(GridField $grid, $request) {
		if(!$paginator = $grid->getConfig()->getComponentByType('GridFieldPaginator')) {
			$this->httpError(404, 'Paginator component not found');
		}

		$move  = $request->postVar('move');
		$field = $this->getSortField();

		$list  = $grid->getList();
		$manip = $grid->getManipulatedList();
                
                if($list instanceof ManyManyList){
                    $owner = $grid->Form->getRecord();
                    list($parentClass, $componentClass, $parentField, $componentField, $table) = $owner->many_many($grid->getName());
                    $many_many = ' AND "' . $parentField . '" = ' . $owner->ID;
                }else{
                    $many_many = '';
                }

		$existing = $manip->map('ID', $field)->toArray();
		$values   = $existing;
		$order    = array();

		$id = isset($move['id']) ? (int) $move['id'] : null;
		$to = isset($move['page']) ? $move['page'] : null;

		if(!isset($values[$id])) {
			$this->httpError(400, 'Invalid item ID');
		}

		$this->populateSortValues($list, $many_many);

		$page = ((int) $grid->getState()->GridFieldPaginator->currentPage) ?: 1;
		$per  = $paginator->getItemsPerPage();

		if($to == 'prev') {
			$swap = $list->sort($this->getSortField())->limit(1, ($page - 1) * $per - 1)->first();
			$values[$swap->ID] = $swap->$field;

			$order[] = $id;
			$order[] = $swap->ID;

			foreach($existing as $_id => $sort) {
				if($id != $_id) $order[] = $_id;
			}
		} elseif($to == 'next') {
			$swap = $list->sort($this->getSortField())->limit(1, $page * $per)->first();
			$values[$swap->ID] = $swap->$field;

			foreach($existing as $_id => $sort) {
				if($id != $_id) $order[] = $_id;
			}

			$order[] = $swap->ID;
			$order[] = $id;
		} else {
			$this->httpError(400, 'Invalid page target');
		}

		$this->reorderItems($list, $values, $order, $many_many);

		return $grid->FieldHolder();
	}

	protected function reorderItems($list, array $values, array $order, $many_many) {
		// Get a list of sort values that can be used.
		$pool = array_values($values);
		sort($pool);

		// Loop through each item, and update the sort values which do not
		// match to order the objects.
		foreach(array_values($order) as $pos => $id) {
			if($values[$id] != $pool[$pos]) {
				DB::query(sprintf(
					'UPDATE "%s" SET "%s" = %d WHERE %s',
					$this->getSortTable($list),
					$this->getSortField(),
					$pool[$pos],
					$this->getSortTableClauseForIds($list, $id).$many_many
				));
			}
		}
	}

	protected function populateSortValues(DataList $list, $many_many) {
		$list   = clone $list;
		$field  = $this->getSortField();
		$table  = $this->getSortTable($list);
		$clause = sprintf('"%s"."%s" = 0', $table, $this->getSortField());

		foreach($list->where($clause)->column('ID') as $id) {
			$max = DB::query(sprintf('SELECT MAX("%s") + 1 FROM "%s"', $field, $table));
			$max = $max->value();

			DB::query(sprintf(
				'UPDATE "%s" SET "%s" = %d WHERE %s',
				$table,
				$field,
				$max,
				$this->getSortTableClauseForIds($list, $id).$many_many
			));
		}
	}

	protected function getSortTableClauseForIds(DataList $list, $ids) {
		if(is_array($ids)) {
			$value = 'IN (' . implode(', ', array_map('intval', $ids)) . ')';
		} else {
			$value = '= ' . (int) $ids;
		}

		if($list instanceof ManyManyList) {
			$extra = $list->getExtraFields();
			$key   = $list->getLocalKey();

			if(array_key_exists($this->getSortField(), $extra)) {
				return sprintf('"%s" %s', $key, $value);
			}
		}

		return "\"ID\" $value";
	}

}
