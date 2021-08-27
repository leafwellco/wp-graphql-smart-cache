<?php

class SaveQueryCest {
	public function _before( FunctionalTester $I ) {
		// Make sure that is gone.
		$I->dontHavePostInDatabase(['post_title' => 'Hello world!']);
	}

	public function saveQueryWithSpecificNameTest( FunctionalTester $I ) {
		$I->wantTo( 'Save a named graphql query' );

		$query = "query my_yoyo_query {\n  __typename\n}\n";
		$query_hash = hash( 'sha256', $query );

		$I->sendPost('graphql', [
			'query' => $query,
			'queryId' => $query_hash
		] );
		$I->seeResponseContainsJson([
			'data' => [
				'__typename' => 'RootQuery'
			]
		]);
		$I->seePostInDatabase( [
			'post_type'    => 'graphql_query',
			'post_status'  => 'publish',
			'post_name'    => $query_hash,
			'post_content' => $query,
			'post_title'    => 'my_yoyo_query',
		] );

		$I->sendPost('graphql', [
			'query' => $query,
			'queryId' => $query_hash
		] );
		$I->seeResponseContainsJson([
			'data' => [
				'__typename' => 'RootQuery'
			]
		]);

		// clean up
		$I->dontHavePostInDatabase( [ 'post_name' => $query_hash ] );
	}

	public function saveQueryWithAliasNameSavesTest( FunctionalTester $I ) {
		$I->wantTo( 'Save a graphql query with a query id/hash that does not match, saves as alias' );

		$query = "{\n  __typename\n}\n";

		// Make sure query hash we use doesn't match
		$query_hash = hash( 'sha256', $query );
		$query_alias = 'test-save-query-creates-alias';

		$I->dontSeeTermInDatabase( [ 'name' => 'graphql_query_label' ] );
		$I->sendPost('graphql', [
			'query' => $query,
			'queryId' => $query_alias
		] );
		$I->seeResponseContainsJson([
			'data' => [
				'__typename' => 'RootQuery'
			]
		]);
		$I->seePostInDatabase( [
			'post_name' => $query_hash,
		] );
		$I->seeTermInDatabase( [ 'name' => $query_hash ] );
		$I->seeTermInDatabase( [ 'name' => $query_alias ] );
	}

	public function saveQueryWithInvalidIdFailsTest( FunctionalTester $I ) {
		$I->wantTo( 'Save a graphql query that is invalid, should return error' );

		$query = "{\n  __typename";
		$query_hash = hash( 'sha256', $query );

		$I->sendPost('graphql', [
			'query' => $query,
			'queryId' => $query_hash
		] );
		$I->seeResponseContainsJson([
			'errors' => [
				'message' => 'Syntax Error: Expected Name, found <EOF>'
			]
		]);
		$I->dontSeePostInDatabase( [
			'post_type'    => 'graphql_query',
			'post_name'    => $query_hash,
			'post_content' => $query,
		] );
	}
}