<?php

return array(

	'sector_id' => array(
		'value' => '',
		'placeholder' => '',
		'title' => 'Sector ID',
		'description' => 'Ваш индивидуальный номер клиента (Sector ID)',
		'control_type' => 'text'
	),

	'password' => array(
		'value' => '',
		'placeholder' => '',
		'title' => 'Пароль',
		'description' => 'Пароль для электронно-цифровой подписи транзакций',
		'control_type' => 'text'
	),

	'test_mode' => array(
		'value' => 1,
		'title' => 'Тестовый режим работы',
		'description' => 'Использовать эмуляцию реальной работы; средства с покупателя списаны не будут',
		'control_type' => 'select',
		'options' => array(
			0 => 'Нет, использовать рабочий режим',
			1 => 'Да, использовать тестовый режим',
		),
	),

	'tax' => array(
		'value' => 6,
		'title' => 'Код ставки НДС',
		'description' => 'Код ставки НДС для ККТ',
		'control_type' => 'select',
		'options' => array(
			1 => 'ставка НДС 20%',
			2 => 'ставка НДС 10%',
			3 => 'ставка НДС расч. 18/118',
			4 => 'ставка НДС расч. 10/110',
			5 => 'ставка НДС 0%',
			6 => 'НДС не облагается',
		),
	)

);
