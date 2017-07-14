<?php

class Cart_manager_model extends CI_Model
{
	private $cart_product_table_name="cart_product";
	private $cart_product_option_table_name="cart_product_option";
	
	public function __construct()
	{
		parent::__construct();

		$this->initialize_cart();

      return;
   }

	public function install()
	{
		$tbl_name=$this->db->dbprefix($this->cart_product_table_name); 
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`cp_id` INT AUTO_INCREMENT
				,`cp_customer_id` INT NOT NULL
				,`cp_order_id` INT DEFAULT 0
				,`cp_product_id` INT NOT NULL
				,`cp_quantity`	INT DEFAULT 1
				,`cp_price` DOUBLE NOT NULL
				,PRIMARY KEY (cp_id)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$tbl_name=$this->db->dbprefix($this->cart_product_option_table_name); 
		$this->db->query(
			"CREATE TABLE IF NOT EXISTS $tbl_name (
				`cpo_id` INT AUTO_INCREMENT
				,`cpo_cp_id` INT 
				,`cpo_type` VARCHAR(255)
				,`cpo_value` VARCHAR(1023)
				,PRIMARY KEY (cpo_id)	
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);

		$this->load->model("module_manager_model");

		$this->module_manager_model->add_module("cart","cart_manager");
		$this->module_manager_model->add_module_names_from_lang_file("cart");
		
		return;
	}

	public function uninstall()
	{
		return;
	}

	private function initialize_cart()
	{
		if(!$this->session->userdata("cart"))
			$this->session->set_userdata("cart",array(
				"last_id"		=> 0
				,"products"		=> array()
				,"total_price"	=> 0
			));

		return;
	}
	
	public function add_product($product_id,$options,$quantity,$price)
	{
		$cart=$this->session->userdata("cart");

		$cart['products'][$cart['last_id']]=array(
			'cart_index'	=> $cart['last_id']
			,'product_id'	=> $product_id
			,'options'		=> $options
			,'quantity'		=> $quantity
			,'price'			=> $price
		);

		$cart['last_id']++;
		$cart['total_price']+=$price*$quantity;

		$this->session->set_userdata("cart",$cart);

		$this->save(0);

		return;
	}

	public function remove_item($item_index)
	{
		$cart=$this->session->userdata("cart");
		if(!isset($cart['products'][$item_index]))
			return;

		$price=$cart['products'][$item_index]['price'];
		$quantity=$cart['products'][$item_index]['quantity'];
		$cart['total_price']-=$price*$quantity;
		unset($cart['products'][$item_index]);

		$this->session->set_userdata("cart",$cart);

		$this->save(0);

		return;
	}

	//if customer has been logged in, 
	//it stores cart's products in database for future use  
	//in this version we don't save cart before submitting order
	private function save($order_id)
	{
		if(!$order_id)
			return;

		$this->load->model("customer_manager_model");
		if(!$this->customer_manager_model->has_customer_logged_in())
			return;

		$customer_id=$this->customer_manager_model->get_logged_customer_id();

		$this->delete_cart_products($customer_id,$order_id);

		$cart=$this->session->userdata("cart");

		foreach($cart['products'] as $product)
		{
			$this->db->insert($this->cart_product_table_name,array(
				'cp_customer_id' 		=> $customer_id
				,'cp_order_id' 		=> $order_id
				,'cp_product_id' 		=> $product['product_id']
				,'cp_quantity'			=> $product['quantity']
				,'cp_price' 			=> $product['price']

			));

			$cp_id=$this->db->insert_id();

			if(!$product['options'])
				continue;

			$op_ins=array();
			foreach($product['options'] as $type => $value)
				$op_ins[]=array(
					'cpo_cp_id' 	=> $cp_id 
					,'cpo_type'		=> $type
					,'cpo_value'	=> $value
				);

			$this->db->insert_batch($this->cart_product_option_table_name,$op_ins);
		}

		return;
	}

	private function delete_cart_products($customer_id, $order_id)
	{
		$cp_ids=$this->db
			->select("GROUP_CONCAT(cp_id) AS cp_ids")
			->from($this->cart_product_table_name)
			->where("cp_customer_id",$customer_id)
			->where("cp_order_id",$order_id)
			->get()
			->row_array()['cp_ids'];

		if(!$cp_ids)
			return;

		$this->db
			->where("cpo_cp_id IN ($cp_ids)")
			->delete($this->cart_product_option_table_name);

		$this->db
			->where("cp_customer_id",$customer_id)
			->where("cp_order_id",$order_id)
			->delete($this->cart_product_table_name);

		return;
	}

	public function get_cart($lang_id)
	{
		$cart=$this->session->userdata("cart");
		if(!$cart['products'])
			return $cart;

		$pids=array();
		foreach($cart['products'] as $product)
			if(!in_array( $product['product_id'], $pids))
				$pids[]=$product['product_id'];

		$this->load->model("product_manager_model");
		$products=$this->product_manager_model->get_products(array(
			"lang"				=> $lang_id
			,"product_ids"		=> $pids
			,"post_date_le"	=> get_current_time()
			,"active"			=> 1
			,"group_by"			=> "product_id"

		));

		$aproducts=array();
		foreach($products as $product)
			$aproducts[$product['product_id']]=$product;

		foreach($cart['products'] as &$product)
		{
			$product_id=$product['product_id'];
			$product['product_name']=$aproducts[$product_id]['pc_title'];
		}

		return $cart;
	}

	
}