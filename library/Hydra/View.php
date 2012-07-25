<?php
class View extends View_Abstract {
	
	
	public static function factory($spec, array $vars = array(), array $opt = array()) {
		return new self($spec, $vars, $opt);
	}
	
	/**
	 * @see View_Abstract::_run()
	 */
	protected function _run() {
		foreach($this->_vars as $key => &$each) {
			if($each instanceof View_Abstract) {
				$each = $each->render();
			}
		}
		include $this->_templatePath;
	}
}