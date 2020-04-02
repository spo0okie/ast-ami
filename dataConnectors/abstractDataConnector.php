<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 09.03.2020
 * Time: 16:58
 */

/**
 * абстрактный класс коннектора к внешним данным
 * должен уметь подключаться к внешнему источнику и
 * толкать в него данные
 */

abstract class abstractDataConnector {

	/*инициировать коннектор с передачей массива с учетными данными*/
	abstract public function __construct($conParams=null);

	/*подключиться к внешнему серверу с переданными учетными данными*/
	abstract public function connect();

	/*проверяет соединение, возвращает true если соединение потеряно*/
	abstract public function checkConnection();

	/*послать данные на внешний сервис*/
	abstract public function sendData($data);

	/*разовать соединение согласно протокола взаимодействия*/
	abstract public function disconnect();

	/*возвращает тип коннектора*/
	abstract public function getType();
}