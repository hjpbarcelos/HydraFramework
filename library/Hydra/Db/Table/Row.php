<?php
class Db_Table_Row implements ArrayAccess, IteratorAggregate {
	/**
	 * Os dados das colunas da linha da tabela
	 * @var array
	 */
	protected $_data = array();

	/**
	 * Este atributo � setado como uma c�pia dos dados
	 * quando estes s�o buscados na tabela do banco de dados
	 * ou especificado como uma nova tupla no construtor ou
	 * quando dados 'sujos' s�o enviados ao banco de dados.
	 * @var array
	 */
	protected $_cleanData = array();
	
	/**
	 * Rastreia as colunas onde os dados foram atualizados,
	 * para permitir opera��es de INSERT e UPDATE mais espec�ficas
	 * @var array
	 */
	protected $_modifiedFields = array();

	/**
	 * Inst�ncia do objeto Db_Table que criou este objeto
	 * @var Db_Table
	 */
	protected $_table = null;

	/**
	 * Se TRUE, temos uma refer�ncia a um objeto Db_Table.
	 * Ser� FALSE ap�s a desserializa��o.
	 * @var boolean
	 */
	protected $_connected = true;

	/**
	 * Uma linha pode ser marcada como somente leitura se ela cont�m
	 * colunas que n�o s�o fisicamente representadas no schema da tabela
	 * (ex.: colunas avaliadas/Db_Expressions).
	 *
	 * Isso tamb�m pode ser configurado em tempo de execu��o,
	 * para proteger os dados da linha.
	 * @var boolean
	 */
	protected $_readOnly = false;

	/**
	 * O nome da tabela do objeto Db_table
	 * @var string
	 */
	protected $_tableName;

	/**
	 * As colunas que s�o chave prim�ria da linha
	 * @var array
	 */
	protected $_primary;

	/**
	 * Construtor.
	 *
	 * Par�metros de configura��o suportados:
	 * - table		=>	(string) o nome da tabela ou a inst�ncia de Db_Table
	 * - data		=>	(array) os dados das colunas nesta linha
	 * - stored		=>	(boolean) se os dados s�o provindos do banco de dados ou n�o
	 * - readOnly	=>	(boolean) se � permitido ou n�o alterar os dados desta linha
	 *
	 * @param array $config
	 * @throws Db_Table_Row_Exception
	 */
	public function __construct(array $config = array()) {
		if(isset($config['table'])) {
			if($config['table'] instanceof Db_Table) {
				$this->_table = $config['table'];
			} else if($config['table'] != null){
				$this->_table = $this->_getTableFromString($config['table']);
			}
			$this->_tableName = $this->_table->getName();
		}

		if(isset($config['data'])) {
			if(!is_array($config['data'])) {
				throw new Db_Table_Row_Exception('Os dados precisam ser um array.');
			}
			$this->_data = $config['data'];
		}

		if(isset($config['stored']) && $config['stored'] === true) {
			$this->_cleanData = $this->_data;
		}

		if (isset($config['readOnly']) && $config['readOnly'] === true) {
			$this->setReadOnly(true);
		}

		if (($table = $this->getTable())) {
			$info = $table->info();
			$this->_primary = (array) $info['primary'];
		}

		$this->init();
	}

	/**
	 * Retorna o valor de um campo da tabela ou de um campo extra.
	 * A preced�ncia � 
	 * 	campo > campo extra
	 *
	 * @param string $columnName
	 * @return mixed
	 * @throws Db_Table_Row_Exception
	 */
	public function get($columnName) {
		if(!$this->exists($columnName)) {
			throw new Db_Table_Row_Exception(sprintf('A coluna "%s" n�o existe na tabela "%s"', $columnName, $this->_tableName));
		}
		return $this->_data[$columnName];
	}

	/**
	 * Seta um valor para o campo da tabela.
	 * 
	 * @param string $columnName
	 * @param mixed $value
	 * @return Db_Table_Row : fluent interface
	 * @throws Db_Table_Row_Exception
	 */
	public function set($columnName, $value) {
		if(!$this->exists($columnName)) {
			throw new Db_Table_Row_Exception(sprintf('A coluna "%s" n�o existe na tabela "%s"', $columnName, $this->_tableName));
		}
		
		$this->_data[$columnName] = $value;
		$this->_modifiedFields[$columnName] = true;
		
		return $this;
	}

	/**
	 * Verifica se o campo existe na tabela
	 *
	 * @param string $columnName
	 * @return boolean
	 */
	public function exists($columnName) {
		return isset($this->_data[$columnName]);
	}

	/**
	 * Remove o valor de um campo da tabela
	 *
	 * @param string $columnName
	 * @return Db_Table_Row : fluent interface
	 * @throws Db_Table_Row_Exception
	 */
	public function remove($columnName) {
		if(!$this->exists($columnName)) {
			throw new Db_Table_Row_Exception(sprintf('A coluna "%s" n�o existe na tabela "%s"', $columnName, $this->_tableName));
		}

		if($this->isConnected() && in_array($columnName, $this->_table->info(Db_Table::PRIMARY))) {
			throw new Db_Table_Row_Exception(sprintf('A coluna "%s" � chave prim�ria na tabela "%s" e n�o pode ser removida', $columnName, $this->_tableName));
		}
		
		unset($this->_data[$columnName]);
		return $this;
	}
	
	/**
	 * @param string $columnName
	 * @see Db_Table_Row::get()
	 */
	public function __get($columnName) {
		return $this->get($columnName);
	}
	
	/**
	 * @param string $columnName
	 * @param mixed $value
	 */
	public function __set($columnName, $value) {
		return $this->set($columnName, $value);
	}
	
	/**
	 * @param string $columnName
	 */
	public function __isset($columnName) {
		return $this->exists($columnName);
	}
	
	/**
	 * @param string $columnName
	 */
	public function __unset($columnName) {
		return $this->remove($columnName);
	}
	
	/**
	 * Retorna os dados para a serializa��o.
	 * 
	 * @return array
	 */
	public function __sleep() {
		return array('_tableName', '_primary', '_data', '_cleanData', '_modifiedFields', '_readOnly');
	}
	
	/**
	 * N�o � esperado que uma linha desserializada tenha
	 * acesso a uma conex�o ativa com o banco de dados
	 * 
	 * @return void
	 */
	public function __wakeup() {
		$this->_connected = false;
	}
	
	/**
	 * (non-PHPdoc)
	 * @see ArrayAccess::offsetGet()
	 */
	public function offsetGet($offset) {
		return $this->get($offset);
	}
	
	/**
	 * (non-PHPdoc)
	 * @see ArrayAccess::offsetSet()
	 */
	public function offsetSet($offset, $value) {
		$this->set($offset, $value);
	}
	
	/**
	 * (non-PHPdoc)
	 * @see ArrayAccess::offsetExists()
	 */
	public function offsetExists($offset) {
		return $this->exists($offset);
	}
	
	/**
	 * (non-PHPdoc)
	 * @see ArrayAccess::offsetUnset()
	 */
	public function offsetUnset($offset) {
		return $this->remove($offset);
	}
	
	/**
	 * (non-PHPdoc)
	 * @see IteratorAggregate::getIterator()
	 */
	public function getIterator() {
		return new ArrayIterator((array) $this->_data);
	}
	
	/**
	 * Converte o objeto em um array.
	 * 
	 * @return array
	 */
	public function toArray() {
		return (array) $this->_data;
	}
	
	/**
	 * Seta os dados do objeto a partir de um array.
	 * 
	 * @param array $data
	 * @return Db_Table_Row : fluent interface
	 */
	public function setFromArray(array $data) {
		foreach($data as $key => $val) {
			if(isset($this->_data[$key])) {
				$this->set($key, $val);
			}
		}
		
		return $this;
	}
	
	/**
	 * Inicializa o objeto.
	 * � chamado no final do construtor {@link __construct()}
	 */
	public function init() {
		
	}
	
	/**
	 * Retorna um objeto Db_Table ou null, se a linha est� 'desconectada'.
	 * 
	 * @return Db_Table|null
	 */
	public function getTable() {
		return $this->_table;
	}
	
	/**
	 * Seta uma tabela para o objeto para restabelecer a conex�o
	 * com o banco de dados para o objeto desserializado.
	 * 
	 * @param Db_Table $table
	 * @return boolean
	 * @throws Db_Table_Row_Exception
	 */
	public function setTable(Db_Table $table = null) {
		if($table === null) {
			$this->_table = null;
			$this->_connected = false;
			return false;
		}
		
		if($table->getName() != $this->_tableName) {
			throw new Db_Table_Row_Exception(sprintf('A tabela especificada "%s" n�o � a mesma configurada no objeto Db_Table_Row ("%s")', 
													$table->getName(), 
													$this->_tableName
											));
		}
		
		$this->_table = $table;
		$this->_tableName = $table->getName();
		$info = $this->_table->info();
		
		$tableCols = $info['cols'];
		$rowCols = array_keys($this->_data);
		 
		if($tableCols != $rowCols) {
			throw new Db_Table_Row_Exception(sprintf('As colunas da tabela (%s) n�o s�o as mesmas colunas da linha (%s)',
													join(', ', $tableCols),
													join(', ', $rowCols)
											));
		}
		
		$tablePk = $info['primary'];
		$rowPk = (array) $this->_primary;
		if(!array_intersect($rowPk, $tablePk) == $rowPk) {
			throw new Db_Table_Row_Exception(sprintf('A chave prim�ria da tabela (%s) n�o � a mesma da linha (%s)',
													join(', ', $tablePk),
													join(', ', $rowPk)
											));
		}
		
		$this->_connected = true;
		return true;
	}
	
	/**
	 * Retorna o nome da tabela � qual o objeto est� vinculado.
	 * 
	 * @return string
	 */
	public function getTableName() {
		return $this->_tableName;
	}

	/**
	 * Testa o status de conex�o do objeto.
	 * 
	 * @return boolean
	 */
	public function isConnected() {
		return $this->_connected;
	}
	
	/**
	 * Testa se o objeto � somente-leitura.
	 * 
	 * @return boolean
	 */
	public function isReadOnly() {
		return $this->_readOnly;
	}
	
	/**
	 * Seta o status de somente-leitura do objeto.
	 * 
	 * @param boolean $opt
	 * @return Db_Table_Row : fluent interface
	 */
	public function setReadOnly($opt) {
		$this->_readOnly = (bool) $opt;
		return $this;
	}	
	
	/**
	 * Retorna uma inst�ncia do objeto Db_Table_Select
	 * criado pelo objeto Db_Table pai deste objeto.
	 * 
	 * @param mixed $cols
	 * @return Db_Table_Select
	 * @throws Db_Table_Row_Exception
	 */
	public function select($cols = array()) {
		$table = $this->_getRequiredTable();
		return $table->select($cols);
	}
	
	/**
	 * Salva as propriedades no banco de dados.
	 * 
	 * Realiza inser��es e atualiza��es inteligentes e recarrega as 
	 * propriedades com os valores atualizados da tabela em caso de sucesso.
	 * 
	 * @return mixed : a chave prim�ria do registro
	 * @throws Db_Table_Exception
	 */
	public function save() {
		if($this->isReadOnly()) {
			throw new Db_Table_Row_Exception('Este objeto Db_Table_Row est� marcado como somente-leitura.');
		}
		/* Se _cleanData est� vazio,
		 * temos uma opera��o de inser��o,
		 * se n�o, temos uma atualiza��o.
		 */
		if(empty($this->_cleanData)) {
			return $this->_doInsert();
		} else {
			return $this->_doUpdate();
		}
	}
	
	/**
	 * Realiza a inser��o dos dados da linha na tabela.
	 * 
	 * @return mixed : a chave-prim�ria da linha inserida
	 */
	public function _doInsert() {
		// L�gica de pr�-inser��o
		$this->_preInsert();
		
		$data = array_intersect_key($this->_data, $this->_modifiedFields);
		$pk = $this->_getRequiredTable()->insert($data);

		if(is_array($pk)) {
			$newPk = $pk;
		} else {
			$tmpPk = (array) $this->_primary;
			$newPk = array(current($tmpPk) => $pk);
		}
		
		$this->_data = array_merge($this->_data, $newPk);
		
		// L�gica de p�s-inser��o
		$this->_postInsert();
		
		// Atualiza _cleanData para refletir os dados que foram inseridos
		$this->refresh();
		
		return $pk;
	}
	
	/**
	 * Atualiza os dados da linha na tabela.
	 * 
	 * @return mixed: a chave prim�ria da linha alterada
	 */
	protected function _doUpdate() {
		/* 
		 * Cria uma express�o para a cl�usula WHERE
		 * com base no valor da chave prim�ria
		 */
		$where = $this->_getWhereQuery(false);

		// L�gica de pr�-atualiza��o
		$this->_preUpdate();
		
		// Descobre quais colunas foram modificadas.
		$diffData = array_intersect_key($this->_data, $this->_modifiedFields);
		
		$table = $this->_getRequiredTable();
		
		// Atualiza apenas se houver dados alterados
		if(!empty($diffData)) {
			$table->update($diffData, $where);
		}
		
		// L�gica de p�s-atualiza��o
		$this->_postUpdate();
		
		/* Atualiza os dados caso triggers no SGBD 
		 * tenham alterado o valor de qualquer coluna.
		 * Tamb�m reseta _cleanData 
		 */		
		$this->refresh();
		
		$pk = $this->_getPrimaryKey(true);
		if(count($pk) == 1) {
			return current($pk);
		}
		
		return $pk;
	}
	
	/**
	 * Remove a linha da tabela
	 * 
	 * @throws Db_Table_Row_Exception
	 * @return int : o n�mero de linhas removidas
	 */
	public function delete() {
		if($this->isReadOnly()) {
			throw new Db_Table_Row_Exception('Este objeto Db_Table_Row est� marcado como somente-leitura.');
		}
		
		/* 
		 * Cria uma express�o para a cl�usula WHERE
		 * com base no valor da chave prim�ria
		 */
		$where = $this->_getWhereQuery(false);
		
		// L�gica de pr�-remo��o
		$this->_preDelete();
		
		$table = $this->_getRequiredTable();
		
		// Executa a remo��o
		$result = $table->delete($where);
		
		// L�gica de p�s-remo��o
		$this->_postDelete();
		
		/*
		 * Seta todas as colunas com o valor NULL
		 */
		$this->_data = array_combine(
			array_keys($this->_data),
			array_fill(0, count($this->_data), null)
		);
		
		return $result;
	}
	
	/**
	 * Se o objeto Db_Table for necess�rio para executar uma opera��o,
	 * deve-se invocar este m�todo para busc�-lo.
	 * Ele lan�ar� uma excess�o caso a tabela n�o seja encontrada.
	 *
	 * @return Db_Table
	 * @throws Db_Table_Row_Exception
	 */
	protected function _getRequiredTable() {
		$table = $this->getTable();
		if(!$table instanceof Db_Table) {
			throw new Db_Table_Row_Exception('O objeto Db_Table_Row n�o est� associado a um objeto Db_Table.');
		}
		return $table;
	}
	
	/**
	 * Retorna um array associativo contendo a chave prim�ria da linha
	 * 
	 * @param boolean $useDirty
	 * @throws Db_Table_Row_Exception
	 */
	protected function _getPrimaryKey($useDirty = false) {
		if(!is_array($this->_primary)) {
			throw new Db_Table_Row_Exception('A chave prim�ria deve estar setada como um array.');
		}
		
		$pk = array_flip($this->_primary);
		if($useDirty) {
			$array = array_intersect_key($this->_data, $primary);
		} else {
			$array = array_intersect_key($this->_cleanData, $primary);
		}
		
		if(count($pk) != count($array)) {
			throw new Db_Table_Row_Exception(sprintf(
												'A tabela especificada "%s" n�o possui a mesma chave prim�ria (%s) que a linha (%s).',
												$this->_tableName,
												join(', ', $pk),
												join(', ', $array)
											));
		}
		
		return $array;
	}
	
	/**
	 * Gera uma cl�usula WHERE com base na chave prim�ria da linha.
	 * 
	 * @param boolean $useDirty
	 * @return array : array de cl�usulas WHERE
	 */
	protected function _getWhereQuery($useDirty = true) {
		$where = array();
		
		$adapter = $this->_table->getAdapter();

		$pk = $this->_getPrimaryKey($useDirty);
		
		$info = $this->_table->info();
		$tableName = $tableName = $adapter->quoteIdentifier($info[Db_Table::NAME]);
		$metadata = $info[Db_Table::METADATA];
		
		$where = array();
		foreach($pk as $col => $val) {
			$type = $metadata[$col]['DATA_TYPE'];
			$colName = $adapter->quoteIdentifier($col);
			$where[] = $adapter->quoteInto($tableName . '.' . $colName . ' = ?', $val);
		}
		return $where;
	}
	
	/**
	 * Atualiza os dados do objeto com os dados da linha
	 * da tabela no banco de dados.
	 * 
	 * @return void
	 * @throws Db_Table_Row_Exception
	 */
	public function refresh() {
		$where = $this->_getWhereQuery();
		$row = $this->_getRequiredTable()->fetchOne($where);
		
		if($row === null) {
			throw new Db_Table_Row_Exception('N�o foi poss�vel atualizar a linha da tabela. 
											Erro ao busc�-la no banco de dados.');
		}
		
		$this->_data = $row->toArray();
		$this->_cleanData = $this->_data;
		$this->_modifiedFields = array();
	}
	
	/**
	 * L�gica de pr�-inser��o
	 */
	protected function _preInsert() {
		
	}
	
	/**
	 * L�gica de p�s-inser��o
	 */
	protected function _postInsert() {
	
	}
	
	/**
	 * L�gica de pr�-atualiza��o
	 */
	protected function _preUpdate() {
	
	}
	
	/**
	 * L�gica de p�s-atualiza��o
	 */
	protected function _postUpdate() {
	
	}
	
	/**
	 * L�gica de pr�-remo��o
	 */
	protected function _preDelete() {
	
	}
	
	/**
	 * L�gica de p�s-remo��o
	 */
	protected function _postDelete() {
	
	}
	
	/**
	 * Prepara uma refer�ncia para uma tabela.
	 * 
	 * Assegura que todas as refer�ncias est�o setadas
	 * e devidamente formatadas.
	 * 
	 * @param Db_Table $dependent
	 * @param Db_Table $parent
	 * @param string|null $ruleKey
	 * @return array
	 */
	protected function _prepareReference(Db_Table $dependent, Db_Table $parent, $ruleKey = null) {
		$parentName = $parent->getName();
		$map = $dependent->getReference($parentName, $ruleKey);

		if(!isset($map[Db_Table::REF_COLUMNS])) {
			$parentInfo = $parent->info();
			$map[Db_Table::REF_COLUMNS] = array_values((array) $parentInfo['primary']);
		}
		
		$map[Db_Table::COLUMNS] = (array) $map[Db_Table::COLUMNS];
		$map[Db_Table::REF_COLUMNS] = (array) $map[Db_Table::REF_COLUMNS];
		
		return $map;
	}
	
	/**
	 * Encontra o conjunto de linhas dependente deste objeto.
	 * 
	 * @param Db_Table|string $dependentTable
	 * @param string|null $ruleKey
	 * @param Db_Select $select
	 * @return Db_Table_Rowset
	 * @throws Db_Table_Row_Exception
	 */
	public function findDependentRowset($dependentTable, $ruleKey = null, Db_Select $select = null) {
		$adapter = $this->_getRequiredTable()->getAdapter();
		
		if(is_string($dependentTable)) {
			$dependentTable = $this->_getTableFromString($dependentTable);
		} 
		
		if(!$dependentTable instanceof Db_Table) {
			$type = gettype($dependentTable);
			if ($type == 'object') {
				$type = get_class($dependentTable);
			}
			throw new Db_Table_Row_Exception('A tabela dependente deve ser do tipo Db_Table, mas � do tipo ' . $type);			
		}
		
		if($select === null) {
			$select = $dependentTable->select();
		} else {
			$select->setTable($dependentTable);
		}
		
		$map = $this->_prepareReference($dependentTable, $this->_getRequiredTable(), $ruleKey);
		for($i = 0; $i < count($map[Db_Table::COLUMNS]); $i++) {
			$parentColName = $map[Db_Table::REF_COLUMNS][$i];
			$value = $this->_data[$parentColName];
			
			$dependentAdapter = $dependentTable->getAdapter();
			$dependentColName = $map[Db_Table::COLUMNS][$i];
			$dependentCol = $dependentAdapter->quoteIdentifier($dependentColName);
			$dependentInfo = $dependentTable->info();
			
			$type = $dependentInfo[Db_Table::METADATA][$dependentColName]['DATA_TYPE'];
			$select->where($dependentCol . ' = ?', $value, $type);
		}
		
		return $dependentTable->fetchAll($select);
	}
	
	/**
	 * Retorna a linha pai deste objeto.
	 * 
	 * @param Db_Table|string $parentTable
	 * @param string $ruleKey
	 * @param Db_Select $select
	 * @return Db_Table_Row|null
	 * @throws Db_Table_Row_Exception
	 */
	public function findParentRow($parentTable, $ruleKey = null, Db_Select $select = null) {
		$adapter = $this->_getRequiredTable()->getAdapter();
		
		if(is_string($parentTable)) {
			$parentTable = $this->_getTableFromString($parentTable);
		}
		
		if(!$parentTable instanceof Db_Table) {
			$type = gettype($parentTable);
			if ($type == 'object') {
				$type = get_class($parentTable);
			}
			throw new Db_Table_Row_Exception('A tabela pai deve ser do tipo Db_Table, mas � do tipo ' . $type);
		}
		
		if($select === null) {
			$select = $parentTable->select();
		} else {
			$select->setTable($parentTable);
		}
		
		$map = $this->_prepareReference($this->_getRequiredTable(), $parentTable, $ruleKey);
		for($i = 0; $i < count($map[Db_Table::COLUMNS]); $i++) {
			$dependentColName = $map[Db_Table::COLUMNS][$i];
			$value = $this->_data[$dependentColName];
			
			$parentAdapter = $parentTable->getAdapter();
			$parentColName = $map[Db_Table::REF_COLUMNS][$i];
			$parentCol = $parentAdapter->quoteIdentifier($parentColName);
			$parentInfo = $parentTable->info();
			
			$type = $parentInfo[Db_Table::METADATA][$parentColName]['DATA_TYPE'];
			$nullable = $parentInfo[Db_Table::METADATA][$parentColName]['NULLABLE'];
			
			if($value === null) {
				if($nullable == true) {
					$select->where($parentCol . ' IS NULL');
				} else {
					return null;
				}
			} else {
				$select->where($parentCol . ' = ?', $value, $type);
			}
		}
		
		return $parentTable->fetchOne($select);
	}
	
	/**
	 * Retorna as linhas associadas ao objeto atual 
	 * em um relacionamento N:N
	 * 
	 * @param Db_Table|string $matchTable : a tabela contendo os dados desejados
	 * @param Db_Table|string $intersectionTable : a tabela de intersec��o
	 * @param string $callerRefRule : o nome da regra de refer�ncia da tabela 
	 * 								  atual para a tabela de intersec��o
	 * @param string $matchRefRule : o nome da regra de refer�ncia da tabela
	 * 								 de busca para a tabela de intersec��o
	 * @param Db_Select $select : um SQL SELECT statement personalizado 
	 * @return Db_Table_Rowset
	 * @throws Db_Table_Row_Exception
	 */
	public function findManyToManyRowset($matchTable, $intersectionTable, $callerRefRule = null, 
										 $matchRefRule = null, Db_Select $select = null) {
		$adapter = $this->_getRequiredTable()->getAdapter();

		if(is_string($intersectionTable)) {
			$intersectionTable = $this->_getTableFromString($intersectionTable);
		}
		
		if(!$intersectionTable instanceof Db_Table) {
			$type = gettype($intersectionTable);
			if ($type == 'object') {
				$type = get_class($intersectionTable);
			}
			throw new Db_Table_Row_Exception('A tabela de intersec��o deve ser do tipo Db_Table, mas � do tipo ' . $type);
		}
		
		if(is_string($matchTable)) {
			$matchTable = $this->_getTableFromString($matchTable);
		}
		
		if(!$matchTable instanceof Db_Table) {
			$type = gettype($matchTable);
			if ($type == 'object') {
				$type = get_class($matchTable);
			}
			throw new Db_Table_Row_Exception('A tabela de compara��o deve ser do tipo Db_Table, mas � do tipo ' . $type);
		}
		
		if($select === null) {
			$select = $matchTable->select();
		} else {
			$select->setTable($matchTable);
		}
		
		$interInfo = $intersectionTable->info();
		$interAdapter = $intersectionTable->getAdapter();
		$interName = $interInfo[Db_Table::NAME];
		$interSchema = isset($interInfo[Db_Table::SCHEMA]) ? $interInfo[Db_Table::SCHEMA] : null;
		
		$matchInfo = $matchTable->info();
		$matchName = $matchInfo[Db_Table::NAME];
		$matchSchema = isset($matchInfo[Db_Table::SCHEMA]) ? $matchInfo[Db_Table::SCHEMA] : null;
		
		$matchMap = $this->_prepareReference($intersectionTable, $matchTable, $matchRefRule);
		$joinCond = array();
		for($i = 0; $i < count($matchMap[Db_Table::COLUMNS]); $i++) {
			$interCol = $interAdapter->quoteIdentifier('i.' . $matchMap[Db_Table::COLUMNS][$i]);
			$matchCol = $interAdapter->quoteIdentifier('m.' . $matchMap[Db_Table::REF_COLUMNS][$i]);
			$joinCond[] = $interCol . ' = ' . $matchCol;
		}
		$joinCond = join(' AND ', $joinCond);
		
		$select->from(array('i' => $interName), array(), $interSchema)
			   ->innerJoin(array('m' => $matchName), $joinCond, Db_Select::SQL_WILDCARD, $matchSchema);
		
		if($select instanceof Db_Table_Select) {
			$select->setIntegrityCheck(false);
		}
		
		$callerMap = $this->_prepareReference($intersectionTable, $this->_getRequiredTable(), $callerRefRule);
		for($i = 0; $i < count($callerMap[Db_Table::COLUMNS]); $i++) {
			$callerColName = $callerMap[Db_Table::REF_COLUMNS][$i];
			$value = $this->_data[$callerColName];
			
			$interColName = $callerMap[Db_Table::COLUMNS][$i];
			$interCol = $interAdapter->quoteIdentifier('i.' . $interColName);
			$type = $interInfo[Db_Table::METADATA][$interColName]['DATA_TYPE'];
			
			$select->where($interAdapter->quoteInto($interCol . ' = ?', $value, $type));
		}
		
		$stmt = $select->query();
		$data = $stmt->fetchAll(Db::FETCH_ASSOC);
		
		$config = array(
			'table'		=>	$matchTable,
			'data'		=>	$data,
			'rowClass'	=>	$matchTable->getRowClass(),
			'readOnly'	=>	false,
			'stored'	=>	true			
		);
		
		$rowsetClass = $matchTable->getRowsetClass();
		$rowset = new $rowsetClass($config);
		return $rowset;
	}
	
	/**
	 * Retorna uma inst�ncia de Db_Table ou classe derivada
	 * a partir de uma string contendo o nome da tabela ou classe.
	 * @param string $tableName
	 * @return Db_Table
	 */
	protected function _getTableFromString($tableName) {
		try {
			if(class_exists($tableName)){
				return new $tableName($options);
			}
		} catch (Exception $e) {
			$options = array();
			if($table = $this->getTable()) {
				$options['db'] = $table->getAdapter();
			}
			$options['name'] = $tableName;
			
			return new Db_Table($options);
		}
	}
}