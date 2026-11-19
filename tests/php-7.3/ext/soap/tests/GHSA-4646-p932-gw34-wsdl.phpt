--TEST--
GHSA-4646-p932-gw34 (CVE-2026-129506): DTD entity references in WSDL attributes must not outlive their declarations
--SKIPIF--
<?php if (!extension_loaded('soap')) die('skip soap extension not available'); ?>
--INI--
soap.wsdl_cache_enabled=0
--FILE--
<?php
$wsdl = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE definitions [<!ENTITY ns "urn:ghsa-4646"><!ENTITY op "echoString"><!ENTITY loc "http://localhost/ghsa-4646">]>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/"
             xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
             xmlns:xsd="http://www.w3.org/2001/XMLSchema"
             xmlns:tns="urn:ghsa-4646"
             targetNamespace="&ns;">
  <message name="echoStringRequest"><part name="s" type="xsd:string"/></message>
  <message name="echoStringResponse"><part name="r" type="xsd:string"/></message>
  <portType name="P">
    <operation name="&op;">
      <input message="tns:echoStringRequest"/>
      <output message="tns:echoStringResponse"/>
    </operation>
  </portType>
  <binding name="B" type="tns:P">
    <soap:binding style="rpc" transport="http://schemas.xmlsoap.org/soap/http"/>
    <operation name="&op;">
      <soap:operation soapAction="urn:ghsa-4646#echoString"/>
      <input><soap:body use="encoded" namespace="&ns;" encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"/></input>
      <output><soap:body use="encoded" namespace="&ns;" encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"/></output>
    </operation>
  </binding>
  <service name="S">
    <port name="SP" binding="tns:B"><soap:address location="&loc;"/></port>
  </service>
</definitions>
XML;

$file = __DIR__ . '/GHSA-4646-p932-gw34-wsdl.wsdl';
file_put_contents($file, $wsdl);

$client = new SoapClient($file, ['cache_wsdl' => WSDL_CACHE_NONE]);
var_dump($client->__getFunctions());
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/GHSA-4646-p932-gw34-wsdl.wsdl');
?>
--EXPECT--
array(1) {
  [0]=>
  string(28) "string echoString(string $s)"
}
