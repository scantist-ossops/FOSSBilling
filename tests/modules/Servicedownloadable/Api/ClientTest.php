<?php


namespace Box\Mod\Servicedownloadable\Api;


class ClientTest extends \BBTestCase {
    /**
     * @var \Box\Mod\Servicedownloadable\Api\Client
     */
    protected $api = null;

    public function setup(): void
    {
        $this->api= new \Box\Mod\Servicedownloadable\Api\Client();
    }

    public function testgetDi()
    {
        $di = new \Pimple\Container();
        $this->api->setDi($di);
        $getDi = $this->api->getDi();
        $this->assertEquals($di, $getDi);
    }

    public function testsend_fileMissingOrderId()
    {
        $data = array();

        $this->expectException(\FOSSBilling\Exception::class);
        $this->expectExceptionMessage('Order id is required');
        $this->api->send_file($data);
    }

    public function testsend_fileOrderNotFound()
    {
        $data = array(
            'order_id' => 1
        );

        $modelClient = new \Model_Client();
        $modelClient->loadBean(new \DummyBean());

        $dbMock = $this->getMockBuilder('\Box_Database')->getMock();
        $dbMock->expects($this->atLeastOnce())
            ->method('findOne');

        $di = new \Pimple\Container();
        $di['db'] = $dbMock;

        $this->api->setIdentity($modelClient);
        $this->api->setDi($di);

        $this->expectException(\FOSSBilling\Exception::class);
        $this->expectExceptionMessage('Order not found');
        $this->api->send_file($data);
    }

    public function testsend_fileOrderNotActivated()
    {
        $data = array(
            'order_id' => 1,
        );

        $modelClient = new \Model_Client();
        $modelClient->loadBean(new \DummyBean());

        $orderServiceMock = $this->getMockBuilder('\\' . \Box\Mod\Order\Service::class)->getMock();
        $orderServiceMock->expects($this->atLeastOnce())
            ->method('getOrderService');

        $dbMock = $this->getMockBuilder('\Box_Database')->getMock();
        $dbMock->expects($this->atLeastOnce())
            ->method('findOne')
            ->will($this->returnValue(new \Model_ClientOrder()));

        $di = new \Pimple\Container();
        $di['db'] = $dbMock;
        $di['mod_service'] = $di->protect(fn() => $orderServiceMock);

        $this->api->setDi($di);
        $this->api->setIdentity($modelClient);

        $this->expectException(\FOSSBilling\Exception::class);
        $this->expectExceptionMessage('Order is not activated');
        $this->api->send_file($data);
    }

    public function testsend_file()
    {
        $data = array(
            'order_id' => 1,
        );

        $modelClient = new \Model_Client();
        $modelClient->loadBean(new \DummyBean());

        $serviceMock = $this->getMockBuilder('\\' . \Box\Mod\Servicedownloadable\Service::class)->getMock();
        $serviceMock->expects($this->atLeastOnce())
            ->method('sendFile')
            ->will($this->returnValue(true));

        $orderServiceMock = $this->getMockBuilder('\\' . \Box\Mod\Order\Service::class)->getMock();
        $orderServiceMock->expects($this->atLeastOnce())
            ->method('getOrderService')
            ->will($this->returnValue(new \Model_ServiceDownloadable()));

        $mockOrder = new \Model_ClientOrder();
        $mockOrder->loadBean(New \DummyBean());
        $mockOrder->status = "active";

        $dbMock = $this->getMockBuilder('\Box_Database')->getMock();
        $dbMock->expects($this->atLeastOnce())
            ->method('findOne')
            ->will($this->returnValue($mockOrder));

        $di = new \Pimple\Container();
        $di['db'] = $dbMock;
        $di['mod_service'] = $di->protect(fn() => $orderServiceMock);

        $this->api->setDi($di);
        $this->api->setIdentity($modelClient);
        $this->api->setService($serviceMock);

        $result = $this->api->send_file($data);
        $this->assertIsBool($result);
        $this->assertTrue($result);

    }
}
 