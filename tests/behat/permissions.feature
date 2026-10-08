@mod @mod_ailanguageteacher
Feature: AI Language Teacher shows each role only the pages its capabilities allow
  In order to protect lesson content and learner data
  As a site administrator
  I need the builder, scenes and reports to be offered only to the right roles

  Background:
    Given the following "courses" exist:
      | fullname     | shortname |
      | Language 101 | L101      |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher2 | Nia       | Assistant |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | L101   | teacher |
      | student1 | L101   | student |
    And the following "activities" exist:
      | activity          | course | idnumber | name             |
      | ailanguageteacher | L101   | lt1      | Greetings lesson |
    And the following "mod_ailanguageteacher > scenes" exist:
      | activity | title       | phrases                  |
      | lt1      | At the door | Good morning=Buenos días |
    And the following config values are set as admin:
      | name        | value                                         | plugin                |
      | unlockstate | {"status":"unlocked","checkedat":1790000000} | mod_ailanguageteacher |

  Scenario: A learner is offered no teacher pages
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "student1"
    Then I should see "Study"
    And "Reports" "link" should not exist in current page administration
    And "Set up the lesson" "link" should not exist in current page administration
    And "Scenes" "link" should not exist in current page administration

  Scenario: A non-editing teacher can see reports but cannot build or edit the lesson
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "teacher2"
    Then "Reports" "link" should exist in current page administration
    And "Set up the lesson" "link" should not exist in current page administration
    And "Scenes" "link" should not exist in current page administration
    And I am on the "Greetings lesson" "mod_ailanguageteacher > Report" page
    And I should see "Sam Student"

  Scenario: Removing the report capability removes the report
    Given the following "permission overrides" exist:
      | capability                          | permission | role    | contextlevel | reference |
      | mod/ailanguageteacher:viewreports   | Prohibit   | teacher | Course       | L101      |
    When I am on the "Greetings lesson" "ailanguageteacher activity" page logged in as "teacher2"
    Then "Reports" "link" should not exist in current page administration
